<?php

namespace Tests\Unit\Services;

use App\Services\Support\LinearRegression;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test murni matematis — tidak memakai basis data sama sekali.
 */
class LinearRegressionTest extends TestCase
{
    #[Test]
    public function it_fits_a_perfectly_linear_series(): void
    {
        // y = 2 + 3x
        $points = [[0, 2], [1, 5], [2, 8], [3, 11], [4, 14]];

        $model = LinearRegression::fit($points);

        $this->assertEqualsWithDelta(3.0, $model->slope, 1e-9);
        $this->assertEqualsWithDelta(2.0, $model->intercept, 1e-9);
        $this->assertEqualsWithDelta(1.0, $model->rSquared, 1e-9);
        $this->assertSame(5, $model->dataPoints);
        $this->assertFalse($model->isDeclining());
    }

    #[Test]
    public function it_fits_a_noisy_series_to_the_known_ols_solution(): void
    {
        // OLS manual: slope 1.9, intercept 1.9, R² = 1 - 0.70/18.75
        $points = [[0, 2], [1, 4], [2, 5], [3, 8]];

        $model = LinearRegression::fit($points);

        $this->assertEqualsWithDelta(1.9, $model->slope, 1e-9);
        $this->assertEqualsWithDelta(1.9, $model->intercept, 1e-9);
        $this->assertEqualsWithDelta(1.0 - (0.70 / 18.75), $model->rSquared, 1e-9);
        $this->assertEqualsWithDelta(4.75, $model->meanY, 1e-9);
    }

    #[Test]
    public function a_constant_series_is_treated_as_a_perfect_fit(): void
    {
        $model = LinearRegression::fit([[0, 7], [1, 7], [2, 7], [3, 7]]);

        $this->assertEqualsWithDelta(0.0, $model->slope, 1e-9);
        $this->assertEqualsWithDelta(7.0, $model->intercept, 1e-9);
        $this->assertEqualsWithDelta(1.0, $model->rSquared, 1e-9);
        $this->assertFalse($model->isDeclining());
    }

    #[Test]
    public function it_detects_a_declining_trend(): void
    {
        $model = LinearRegression::fit([[0, 50], [1, 40], [2, 30], [3, 20]]);

        $this->assertEqualsWithDelta(-10.0, $model->slope, 1e-9);
        $this->assertEqualsWithDelta(50.0, $model->intercept, 1e-9);
        $this->assertTrue($model->isDeclining());
    }

    #[Test]
    public function r_squared_is_never_negative_or_above_one(): void
    {
        // Extremely erratic data: R² tetap harus berada di dalam rentang [0, 1].
        $model = LinearRegression::fit([[0, 3], [1, 100], [2, 4], [3, 90], [4, 5]]);

        $this->assertGreaterThanOrEqual(0.0, $model->rSquared);
        $this->assertLessThanOrEqual(1.0, $model->rSquared);
    }

    #[Test]
    public function it_predicts_single_values_and_series(): void
    {
        $model = LinearRegression::fit([[0, 1], [1, 3], [2, 5]]);

        $this->assertEqualsWithDelta(1.0, $model->predict(0.0), 1e-9);
        $this->assertEqualsWithDelta(5.0, $model->predict(2.0), 1e-9);
        $this->assertEqualsWithDelta(11.0, $model->predict(5.0), 1e-9);

        $this->assertEqualsWithDelta(
            [1.0, 3.0, 5.0],
            $model->predictNonNegative([0, 1, 2]),
            1e-9,
        );
    }

    #[Test]
    public function predicted_series_is_clamped_to_zero_because_demand_cannot_be_negative(): void
    {
        // y = 10 - 2x -> mulai negatif mulai x = 5
        $model = LinearRegression::fit([[0, 10], [1, 8], [2, 6], [3, 4], [4, 2], [5, 0]]);

        $predictions = $model->predictNonNegative([4, 5, 6, 7, 8]);

        $this->assertSame([2.0, 0.0, 0.0, 0.0, 0.0], array_map(fn ($v) => round($v, 6), $predictions));
    }

    #[Test]
    public function it_requires_at_least_two_points(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minimal 2 titik');

        LinearRegression::fit([[0, 5]]);
    }

    #[Test]
    public function it_rejects_series_where_all_x_values_are_identical(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nilai x identik');

        LinearRegression::fit([[3, 5], [3, 9], [3, 1]]);
    }
}
