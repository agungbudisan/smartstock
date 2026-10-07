<?php

namespace Tests\Feature;

use App\Jobs\AggregateDailyOutboundJob;
use App\Jobs\CalculateMonthlyPredictionJob;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Fase 5 — Registrasi scheduler di routes/console.php.
 */
class SchedulerTest extends TestCase
{
    private function events(): array
    {
        return app(Schedule::class)->events();
    }

    private function nightlyEvent(): Event
    {
        foreach ($this->events() as $event) {
            if ($event->getSummaryForDisplay() !== 'Closure') {
                return $event;
            }
        }

        $this->fail('Event ETL harian tidak terdaftar di scheduler.');
    }

    public function test_the_nightly_etl_event_is_registered(): void
    {
        $events = $this->events();

        $this->assertCount(1, $events, 'Hanya boleh ada satu event ETLProject');
        $this->assertStringContainsString('Agregasi harian', $this->nightlyEvent()->getSummaryForDisplay());
    }

    public function test_it_runs_every_day_at_midnight(): void
    {
        $this->assertSame('0 0 * * *', $this->nightlyEvent()->expression);

        // Cross-check dengan parser cron Laravel: event berikutnya dari
        // 2026-10-07 12:00 harus 2026-10-08 00:00.
        $nextRun = (new CronExpression('0 0 * * *'))->getNextRunDate(
            new \DateTimeImmutable('2026-10-07 12:00:00')
        );

        $this->assertSame('2026-10-08 00:00:00', $nextRun->format('Y-m-d H:i:s'));
    }

    public function test_it_guards_against_overlapping_runs(): void
    {
        $event = $this->nightlyEvent();

        $this->assertTrue($event->withoutOverlapping, 'withoutOverlapping() harus aktif');
        $this->assertTrue($event->onOneServer, 'onOneServer() harus aktif');
        $this->assertSame(1440, $event->expiresAt, 'Mutex dilepas otomatis setelah 24 jam');
    }

    public function test_it_dispatches_aggregation_before_prediction(): void
    {
        Bus::fake();

        $this->nightlyEvent()->run($this->app);

        Bus::assertChained([
            AggregateDailyOutboundJob::class,
            CalculateMonthlyPredictionJob::class,
        ]);
    }

    public function test_both_jobs_are_queueable(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new AggregateDailyOutboundJob);
        $this->assertInstanceOf(ShouldQueue::class, new CalculateMonthlyPredictionJob);
    }

    public function test_the_aggregation_job_is_configured_for_safe_retries(): void
    {
        $job = new AggregateDailyOutboundJob;

        $this->assertSame(3, $job->tries);
        $this->assertGreaterThan(0, $job->backoff);
        $this->assertGreaterThan(0, $job->timeout);
    }

    public function test_the_prediction_job_has_retry_and_timeout_guards(): void
    {
        $job = new CalculateMonthlyPredictionJob;

        $this->assertSame(3, $job->tries);
        $this->assertGreaterThan(0, $job->backoff);
        $this->assertGreaterThan(0, $job->timeout);
        $this->assertSame(14, $job->stockoutThresholdDays);
    }
}
