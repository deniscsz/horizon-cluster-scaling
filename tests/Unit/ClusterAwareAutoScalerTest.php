<?php

declare(strict_types=1);

use Laravel\Horizon\AutoScaler;
use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;
use Deniscsz\HorizonClusterScaling\ClusterAwareAutoScaler;
use Deniscsz\HorizonClusterScaling\MasterCountResolver;

beforeEach(function () {
    $this->innerScaler = Mockery::mock(AutoScaler::class);
    $this->resolver = Mockery::mock(MasterCountResolver::class);
    $this->scaler = new ClusterAwareAutoScaler($this->innerScaler, $this->resolver);
});

function createSupervisor(int $maxProcesses = 10, int $minProcesses = 1): Supervisor
{
    $options = new SupervisorOptions(
        name: 'test-supervisor',
        connection: 'redis',
        queue: 'default',
        maxProcesses: $maxProcesses,
        minProcesses: $minProcesses,
    );

    $supervisor = Mockery::mock(Supervisor::class)->makePartial();
    $supervisor->options = $options;

    return $supervisor;
}

it('delegates directly when disabled', function () {
    config(['horizon-cluster-scaling.enabled' => false]);

    $supervisor = createSupervisor(maxProcesses: 10);

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->with($supervisor);

    $this->resolver->shouldNotReceive('resolve');

    $this->scaler->scale($supervisor);

    expect($supervisor->options->maxProcesses)->toBe(10);
});

it('delegates directly when single master', function () {
    config(['horizon-cluster-scaling.enabled' => true]);

    $this->resolver->shouldReceive('resolve')->once()->andReturn(1);

    $supervisor = createSupervisor(maxProcesses: 10);

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->with($supervisor);

    $this->scaler->scale($supervisor);

    expect($supervisor->options->maxProcesses)->toBe(10);
});

it('divides maxProcesses by master count with ceiling', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $this->resolver->shouldReceive('resolve')->once()->andReturn(3);

    $supervisor = createSupervisor(maxProcesses: 10, minProcesses: 1);

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

    // ceil(10 / 3) = 4
    expect($capturedMax)->toBe(4);
    // ceil(1 / 3) = 1
    expect($capturedMin)->toBe(1);
    // Originals restored
    expect($supervisor->options->maxProcesses)->toBe(10);
    expect($supervisor->options->minProcesses)->toBe(1);
});

it('uses ceiling division for maxProcesses=5 with 3 masters', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $this->resolver->shouldReceive('resolve')->once()->andReturn(3);

    $supervisor = createSupervisor(maxProcesses: 5, minProcesses: 1);

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

it('scales minProcesses proportionally', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $this->resolver->shouldReceive('resolve')->once()->andReturn(3);

    $supervisor = createSupervisor(maxProcesses: 12, minProcesses: 6);

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

    // ceil(12 / 3) = 4, ceil(6 / 3) = 2
    expect($capturedMax)->toBe(4);
    expect($capturedMin)->toBe(2);
});

it('clamps effectiveMax to effectiveMin when division is too aggressive', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $this->resolver->shouldReceive('resolve')->once()->andReturn(10);

    // maxProcesses=3, minProcesses=3 → effectiveMax=ceil(3/10)=1, effectiveMin=ceil(3/10)=1
    $supervisor = createSupervisor(maxProcesses: 3, minProcesses: 3);

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

it('applies config floor for min_effective_max', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => 3]);

    $this->resolver->shouldReceive('resolve')->once()->andReturn(5);

    $supervisor = createSupervisor(maxProcesses: 10, minProcesses: 1);

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

it('restores original values even when inner scaler throws', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $this->resolver->shouldReceive('resolve')->once()->andReturn(2);

    $supervisor = createSupervisor(maxProcesses: 10, minProcesses: 2);

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->andThrow(new RuntimeException('Scaling failed'));

    expect(fn () => $this->scaler->scale($supervisor))
        ->toThrow(RuntimeException::class, 'Scaling failed');

    // Originals must be restored despite exception
    expect($supervisor->options->maxProcesses)->toBe(10);
    expect($supervisor->options->minProcesses)->toBe(2);
});

it('ensures minProcesses never goes below 1', function () {
    config(['horizon-cluster-scaling.enabled' => true]);
    config(['horizon-cluster-scaling.min_effective_max' => null]);

    $this->resolver->shouldReceive('resolve')->once()->andReturn(100);

    $supervisor = createSupervisor(maxProcesses: 10, minProcesses: 1);

    $capturedMin = null;

    $this->innerScaler->shouldReceive('scale')
        ->once()
        ->withArgs(function (Supervisor $s) use (&$capturedMin) {
            $capturedMin = $s->options->minProcesses;

            return true;
        });

    $this->scaler->scale($supervisor);

    expect($capturedMin)->toBe(1);
});
