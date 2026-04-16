<?php

declare(strict_types=1);

use Deniscsz\HorizonClusterScaling\ClusterAwareAutoScaler;
use Deniscsz\HorizonClusterScaling\MasterCountResolver;
use Laravel\Horizon\AutoScaler;
use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;

beforeEach(function () {
    $this->innerScaler = Mockery::mock(AutoScaler::class);
    $this->resolver = Mockery::mock(MasterCountResolver::class);
    $this->scaler = new ClusterAwareAutoScaler($this->innerScaler, $this->resolver);
});

function createSupervisor(int $maxProcesses = 10, int $minProcesses = 1, string $masterName = 'alpha-ab12'): Supervisor
{
    $options = new SupervisorOptions(
        name: $masterName.':test-supervisor',
        connection: 'redis',
        queue: 'default',
        maxProcesses: $maxProcesses,
        minProcesses: $minProcesses,
    );

    $supervisor = Mockery::mock(Supervisor::class)->makePartial();
    $supervisor->options = $options;

    return $supervisor;
}

// ────────────────────────────────────────────────────────────────
// Feature: enabled/disabled and single-master short-circuit
// ────────────────────────────────────────────────────────────────

it('delegates directly when disabled', function () {
    config(['horizon-cluster-scaling.enabled' => false]);

    $supervisor = createSupervisor(maxProcesses: 10);

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->with($supervisor);

    $this->resolver->shouldNotReceive('resolveNames');

    $this->scaler->scale($supervisor);

    expect($supervisor->options->maxProcesses)->toBe(10);
});

it('delegates directly when single master', function () {
    config(['horizon-cluster-scaling.enabled' => true]);

    $this->resolver->shouldReceive('resolveNames')->once()->andReturn(['alpha-ab12']);

    $supervisor = createSupervisor(maxProcesses: 10);

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->with($supervisor);

    $this->scaler->scale($supervisor);

    expect($supervisor->options->maxProcesses)->toBe(10);
});

it('delegates directly when no masters found', function () {
    config(['horizon-cluster-scaling.enabled' => true]);

    $this->resolver->shouldReceive('resolveNames')->once()->andReturn([]);

    $supervisor = createSupervisor(maxProcesses: 10);

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->with($supervisor);

    $this->scaler->scale($supervisor);

    expect($supervisor->options->maxProcesses)->toBe(10);
});

// ────────────────────────────────────────────────────────────────
// Feature: maxProcesses ceiling division (unchanged behavior)
// ────────────────────────────────────────────────────────────────

it('divides maxProcesses by master count with ceiling', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $masters = ['alpha-ab12', 'beta-cd34', 'gamma-ef56'];
    $this->resolver->shouldReceive('resolveNames')->once()->andReturn($masters);

    $supervisor = createSupervisor(maxProcesses: 10, minProcesses: 1, masterName: 'alpha-ab12');

    $capturedMax = null;

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->withArgs(function (Supervisor $s) use (&$capturedMax) {
            $capturedMax = $s->options->maxProcesses;

            return true;
        });

    $this->scaler->scale($supervisor);

    // ceil(10 / 3) = 4
    expect($capturedMax)->toBe(4);
    // Originals restored
    expect($supervisor->options->maxProcesses)->toBe(10);
});

it('uses ceiling division for maxProcesses=5 with 3 masters', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $masters = ['alpha-ab12', 'beta-cd34', 'gamma-ef56'];
    $this->resolver->shouldReceive('resolveNames')->once()->andReturn($masters);

    $supervisor = createSupervisor(maxProcesses: 5, minProcesses: 1, masterName: 'alpha-ab12');

    $capturedMax = null;

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->withArgs(function (Supervisor $s) use (&$capturedMax) {
            $capturedMax = $s->options->maxProcesses;

            return true;
        });

    $this->scaler->scale($supervisor);

    // ceil(5 / 3) = 2
    expect($capturedMax)->toBe(2);
});

it('applies config floor for min_effective_max', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => 3]);

    $masters = ['alpha-ab12', 'beta-cd34', 'gamma-ef56', 'delta-gh78', 'epsilon-ij90'];
    $this->resolver->shouldReceive('resolveNames')->once()->andReturn($masters);

    $supervisor = createSupervisor(maxProcesses: 10, minProcesses: 1, masterName: 'alpha-ab12');

    $capturedMax = null;

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->withArgs(function (Supervisor $s) use (&$capturedMax) {
            $capturedMax = $s->options->maxProcesses;

            return true;
        });

    $this->scaler->scale($supervisor);

    // ceil(10 / 5) = 2, but config floor = 3
    expect($capturedMax)->toBe(3);
});

// ────────────────────────────────────────────────────────────────
// Feature: minProcesses remainder-aware distribution
// ────────────────────────────────────────────────────────────────

it('distributes minProcesses=1 across 3 masters — rank 0 gets 1, others get 0', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $masters = ['alpha-ab12', 'beta-cd34', 'gamma-ef56'];
    $this->resolver->shouldReceive('resolveNames')->andReturn($masters);

    // minProcesses=1, 3 masters: base=0, remainder=1
    // Rank 0 (alpha): 0 + 1 = 1
    $supervisorRank0 = createSupervisor(maxProcesses: 10, minProcesses: 1, masterName: 'alpha-ab12');
    $capturedMin0 = null;

    $this->innerScaler->shouldReceive('scale')
        ->withArgs(function (Supervisor $s) use (&$capturedMin0, $supervisorRank0) {
            if ($s === $supervisorRank0) {
                $capturedMin0 = $s->options->minProcesses;
            }

            return true;
        });

    $this->scaler->scale($supervisorRank0);

    expect($capturedMin0)->toBe(1);

    // Rank 1 (beta): 0
    $supervisorRank1 = createSupervisor(maxProcesses: 10, minProcesses: 1, masterName: 'beta-cd34');
    $capturedMin1 = null;

    $this->innerScaler->shouldReceive('scale')
        ->withArgs(function (Supervisor $s) use (&$capturedMin1, $supervisorRank1) {
            if ($s === $supervisorRank1) {
                $capturedMin1 = $s->options->minProcesses;
            }

            return true;
        });

    $this->scaler->scale($supervisorRank1);

    expect($capturedMin1)->toBe(0);

    // Rank 2 (gamma): 0
    $supervisorRank2 = createSupervisor(maxProcesses: 10, minProcesses: 1, masterName: 'gamma-ef56');
    $capturedMin2 = null;

    $this->innerScaler->shouldReceive('scale')
        ->withArgs(function (Supervisor $s) use (&$capturedMin2, $supervisorRank2) {
            if ($s === $supervisorRank2) {
                $capturedMin2 = $s->options->minProcesses;
            }

            return true;
        });

    $this->scaler->scale($supervisorRank2);

    expect($capturedMin2)->toBe(0);

    // Cluster total: 1 + 0 + 0 = 1 ✓
});

it('distributes minProcesses=2 across 3 masters — ranks 0,1 get 1, rank 2 gets 0', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $masters = ['alpha-ab12', 'beta-cd34', 'gamma-ef56'];

    // base=0, remainder=2: ranks 0,1 get 1; rank 2 gets 0

    // Rank 0
    $this->resolver->shouldReceive('resolveNames')->andReturn($masters);
    $supervisor0 = createSupervisor(maxProcesses: 10, minProcesses: 2, masterName: 'alpha-ab12');
    $min0 = null;
    $this->innerScaler->shouldReceive('scale')->withArgs(function (Supervisor $s) use (&$min0, $supervisor0) {
        if ($s === $supervisor0) {
            $min0 = $s->options->minProcesses;
        }

        return true;
    });
    $this->scaler->scale($supervisor0);
    expect($min0)->toBe(1);

    // Rank 1
    $supervisor1 = createSupervisor(maxProcesses: 10, minProcesses: 2, masterName: 'beta-cd34');
    $min1 = null;
    $this->innerScaler->shouldReceive('scale')->withArgs(function (Supervisor $s) use (&$min1, $supervisor1) {
        if ($s === $supervisor1) {
            $min1 = $s->options->minProcesses;
        }

        return true;
    });
    $this->scaler->scale($supervisor1);
    expect($min1)->toBe(1);

    // Rank 2
    $supervisor2 = createSupervisor(maxProcesses: 10, minProcesses: 2, masterName: 'gamma-ef56');
    $min2 = null;
    $this->innerScaler->shouldReceive('scale')->withArgs(function (Supervisor $s) use (&$min2, $supervisor2) {
        if ($s === $supervisor2) {
            $min2 = $s->options->minProcesses;
        }

        return true;
    });
    $this->scaler->scale($supervisor2);
    expect($min2)->toBe(0);

    // Cluster total: 1 + 1 + 0 = 2 ✓
});

it('distributes minProcesses=4 across 3 masters as 2,1,1', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $masters = ['alpha-ab12', 'beta-cd34', 'gamma-ef56'];
    $this->resolver->shouldReceive('resolveNames')->andReturn($masters);

    // base=1, remainder=1: rank 0 gets 2, ranks 1,2 get 1

    $supervisor0 = createSupervisor(maxProcesses: 10, minProcesses: 4, masterName: 'alpha-ab12');
    $min0 = null;
    $this->innerScaler->shouldReceive('scale')->withArgs(function (Supervisor $s) use (&$min0, $supervisor0) {
        if ($s === $supervisor0) {
            $min0 = $s->options->minProcesses;
        }

        return true;
    });
    $this->scaler->scale($supervisor0);
    expect($min0)->toBe(2);

    $supervisor1 = createSupervisor(maxProcesses: 10, minProcesses: 4, masterName: 'beta-cd34');
    $min1 = null;
    $this->innerScaler->shouldReceive('scale')->withArgs(function (Supervisor $s) use (&$min1, $supervisor1) {
        if ($s === $supervisor1) {
            $min1 = $s->options->minProcesses;
        }

        return true;
    });
    $this->scaler->scale($supervisor1);
    expect($min1)->toBe(1);

    // Cluster total: 2 + 1 + 1 = 4 ✓
});

it('distributes minProcesses=6 evenly across 3 masters', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $masters = ['alpha-ab12', 'beta-cd34', 'gamma-ef56'];
    $this->resolver->shouldReceive('resolveNames')->once()->andReturn($masters);

    // base=2, remainder=0: all get 2
    $supervisor = createSupervisor(maxProcesses: 12, minProcesses: 6, masterName: 'beta-cd34');

    $capturedMax = null;
    $capturedMin = null;

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->withArgs(function (Supervisor $s) use (&$capturedMax, &$capturedMin) {
            $capturedMax = $s->options->maxProcesses;
            $capturedMin = $s->options->minProcesses;

            return true;
        });

    $this->scaler->scale($supervisor);

    // ceil(12 / 3) = 4, floor(6 / 3) = 2
    expect($capturedMax)->toBe(4);
    expect($capturedMin)->toBe(2);
});

it('effectiveMin can be zero for non-remainder hosts', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $masters = ['alpha-ab12', 'beta-cd34', 'gamma-ef56'];
    $this->resolver->shouldReceive('resolveNames')->once()->andReturn($masters);

    // minProcesses=1, rank 2 (gamma) → base=0, remainder=1 → effectiveMin=0
    $supervisor = createSupervisor(maxProcesses: 10, minProcesses: 1, masterName: 'gamma-ef56');

    $capturedMin = null;

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->withArgs(function (Supervisor $s) use (&$capturedMin) {
            $capturedMin = $s->options->minProcesses;

            return true;
        });

    $this->scaler->scale($supervisor);

    // No max(1, ...) clamping — 0 is valid
    expect($capturedMin)->toBe(0);
});

// ────────────────────────────────────────────────────────────────
// Feature: clamp, floor, and edge cases
// ────────────────────────────────────────────────────────────────

it('clamps effectiveMax to effectiveMin when division is too aggressive', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $masters = array_map(fn ($i) => "master-".str_pad((string) $i, 4, '0', STR_PAD_LEFT), range(1, 10));
    sort($masters);
    $this->resolver->shouldReceive('resolveNames')->once()->andReturn($masters);

    // maxProcesses=3, minProcesses=3, 10 masters
    // effectiveMax = ceil(3/10) = 1
    // rank 0: base=0, remainder=3 → effectiveMin=1
    // effectiveMax = max(1, 1) = 1 (clamped)
    $supervisor = createSupervisor(maxProcesses: 3, minProcesses: 3, masterName: $masters[0]);

    $capturedMax = null;
    $capturedMin = null;

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->withArgs(function (Supervisor $s) use (&$capturedMax, &$capturedMin) {
            $capturedMax = $s->options->maxProcesses;
            $capturedMin = $s->options->minProcesses;

            return true;
        });

    $this->scaler->scale($supervisor);

    expect($capturedMax)->toBe(1);
    expect($capturedMin)->toBe(1);
});

it('falls back to ceiling division when current master not in cached names', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    // Cached names do not include the current master (race condition)
    $masters = ['beta-cd34', 'gamma-ef56', 'delta-gh78'];
    $this->resolver->shouldReceive('resolveNames')->once()->andReturn($masters);

    $supervisor = createSupervisor(maxProcesses: 10, minProcesses: 3, masterName: 'alpha-ab12');

    $capturedMin = null;

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->withArgs(function (Supervisor $s) use (&$capturedMin) {
            $capturedMin = $s->options->minProcesses;

            return true;
        });

    $this->scaler->scale($supervisor);

    // Fallback: max(1, ceil(3 / 3)) = 1
    expect($capturedMin)->toBe(1);
});

// ────────────────────────────────────────────────────────────────
// Feature: restoration and error handling
// ────────────────────────────────────────────────────────────────

it('restores original values even when inner scaler throws', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $masters = ['alpha-ab12', 'beta-cd34'];
    $this->resolver->shouldReceive('resolveNames')->once()->andReturn($masters);

    $supervisor = createSupervisor(maxProcesses: 10, minProcesses: 2, masterName: 'alpha-ab12');

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->andThrow(new RuntimeException('Scaling failed'));

    expect(fn () => $this->scaler->scale($supervisor))
        ->toThrow(RuntimeException::class, 'Scaling failed');

    // Originals must be restored despite exception
    expect($supervisor->options->maxProcesses)->toBe(10);
    expect($supervisor->options->minProcesses)->toBe(2);
});

it('restores original values after successful scaling', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $masters = ['alpha-ab12', 'beta-cd34', 'gamma-ef56'];
    $this->resolver->shouldReceive('resolveNames')->once()->andReturn($masters);

    $supervisor = createSupervisor(maxProcesses: 10, minProcesses: 4, masterName: 'alpha-ab12');

    $this->innerScaler->shouldReceive('scale')->once();

    $this->scaler->scale($supervisor);

    // Originals restored for Redis persistence / dashboard
    expect($supervisor->options->maxProcesses)->toBe(10);
    expect($supervisor->options->minProcesses)->toBe(4);
});
