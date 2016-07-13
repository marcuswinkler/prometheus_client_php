<?php


namespace Test\Prometheus;


use PHPUnit_Framework_TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Histogram;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Redis;

class CollectorRegistryTest extends PHPUnit_Framework_TestCase
{
    private $redisAdapter;
    /**
     * @var RenderTextFormat
     */
    private $renderer;

    public function setUp()
    {
        $this->redisAdapter = $this->newRedisAdapter();
        $this->redisAdapter->flushRedis();
        $this->renderer = new RenderTextFormat();
    }

    /**
     * @test
     */
    public function itShouldSaveGaugesInRedis()
    {
        $registry = new CollectorRegistry($this->redisAdapter);
        $metric = $registry->registerGauge('test', 'some_metric', 'this is for testing', array('foo', 'bar'));
        $metric->set(14, array('lalal', 'lululu'));
        $registry->getGauge('test', 'some_metric', array('foo', 'bar'))->set(34, array('lalal', 'lululu'));

        $g = $registry->registerGauge('test', 'some_metric', 'this is for testing', array('foo'));
        $g->set(32, array('lalal'));
        $g->set(35, array('lalab'));

        $g = $registry->registerGauge('test', 'some_metric', 'this is for testing');
        $g->dec();

        $registry = new CollectorRegistry($this->newRedisAdapter());
        $this->assertThat(
            $this->renderer->render($registry->getMetricFamilySamples()),
            $this->equalTo(<<<EOF
# HELP test_some_metric this is for testing
# TYPE test_some_metric gauge
test_some_metric -1
# HELP test_some_metric this is for testing
# TYPE test_some_metric gauge
test_some_metric{foo="lalab"} 35
test_some_metric{foo="lalal"} 32
# HELP test_some_metric this is for testing
# TYPE test_some_metric gauge
test_some_metric{foo="lalal",bar="lululu"} 34

EOF
            )
        );
    }

    /**
     * @test
     */
    public function itShouldSaveCountersInRedis()
    {
        $registry = new CollectorRegistry($this->newRedisAdapter());
        $metric = $registry->registerCounter('test', 'some_metric', 'this is for testing', array('foo', 'bar'));
        $metric->incBy(2, array('lalal', 'lululu'));
        $registry->getCounter('test', 'some_metric', array('foo', 'bar'))->inc(array('lalal', 'lululu'));

        $registry = new CollectorRegistry($this->newRedisAdapter());
        $this->assertThat(
            $this->renderer->render($registry->getMetricFamilySamples()),
            $this->equalTo(<<<EOF
# HELP test_some_metric this is for testing
# TYPE test_some_metric counter
test_some_metric{foo="lalal",bar="lululu"} 3

EOF
            )
        );
    }

    /**
     * @test
     */
    public function itShouldSaveHistogramsInRedis()
    {
        $registry = new CollectorRegistry($this->redisAdapter);
        $metric = $registry->registerHistogram('test', 'some_metric', 'this is for testing', array('foo', 'bar'), array(0.1, 1, 5, 10));
        $metric->observe(2, array('lalal', 'lululu'));
        $registry->getHistogram('test', 'some_metric', array('foo', 'bar'))->observe(13, array('lalal', 'lululu'));
        $registry->getHistogram('test', 'some_metric', array('foo', 'bar'))->observe(7.1, array('lalal', 'lululu'));
        $registry->getHistogram('test', 'some_metric', array('foo', 'bar'))->observe(7.1, array('gnaaha', 'hihihi'));

        $registry->registerHistogram('test', 'some_metric', 'this is for hoeoeoeoe', array('you_got_me'), array(10))
            ->observe(9, array('yes'));

        $registry = new CollectorRegistry($this->redisAdapter);
        $this->assertThat(
            $this->renderer->render($registry->getMetricFamilySamples()),
            $this->equalTo(<<<EOF
# HELP test_some_metric this is for hoeoeoeoe
# TYPE test_some_metric histogram
test_some_metric_bucket{you_got_me="yes",le="10"} 1
test_some_metric_bucket{you_got_me="yes",le="+Inf"} 1
test_some_metric_count{you_got_me="yes"} 1
test_some_metric_sum{you_got_me="yes"} 9
# HELP test_some_metric this is for testing
# TYPE test_some_metric histogram
test_some_metric_bucket{foo="gnaaha",bar="hihihi",le="0.1"} 0
test_some_metric_bucket{foo="gnaaha",bar="hihihi",le="1"} 0
test_some_metric_bucket{foo="gnaaha",bar="hihihi",le="5"} 0
test_some_metric_bucket{foo="gnaaha",bar="hihihi",le="10"} 1
test_some_metric_bucket{foo="gnaaha",bar="hihihi",le="+Inf"} 1
test_some_metric_count{foo="gnaaha",bar="hihihi"} 1
test_some_metric_sum{foo="gnaaha",bar="hihihi"} 7.1
test_some_metric_bucket{foo="lalal",bar="lululu",le="0.1"} 0
test_some_metric_bucket{foo="lalal",bar="lululu",le="1"} 0
test_some_metric_bucket{foo="lalal",bar="lululu",le="5"} 1
test_some_metric_bucket{foo="lalal",bar="lululu",le="10"} 2
test_some_metric_bucket{foo="lalal",bar="lululu",le="+Inf"} 3
test_some_metric_count{foo="lalal",bar="lululu"} 3
test_some_metric_sum{foo="lalal",bar="lululu"} 22.1

EOF
            )
        );
    }

    /**
     * @test
     */
    public function itShouldSaveHistogramsWithoutLabels()
    {
        $registry = new CollectorRegistry($this->redisAdapter);
        $metric = $registry->registerHistogram('test', 'some_metric', 'this is for testing');
        $metric->observe(2);
        $registry->getHistogram('test', 'some_metric')->observe(13);
        $registry->getHistogram('test', 'some_metric')->observe(7.1);

        $registry = new CollectorRegistry($this->redisAdapter);
        $this->assertThat(
            $this->renderer->render($registry->getMetricFamilySamples()),
            $this->equalTo(<<<EOF
# HELP test_some_metric this is for testing
# TYPE test_some_metric histogram
test_some_metric_bucket{le="0.005"} 0
test_some_metric_bucket{le="0.01"} 0
test_some_metric_bucket{le="0.025"} 0
test_some_metric_bucket{le="0.05"} 0
test_some_metric_bucket{le="0.075"} 0
test_some_metric_bucket{le="0.1"} 0
test_some_metric_bucket{le="0.25"} 0
test_some_metric_bucket{le="0.5"} 0
test_some_metric_bucket{le="0.75"} 0
test_some_metric_bucket{le="1"} 0
test_some_metric_bucket{le="2.5"} 1
test_some_metric_bucket{le="5"} 1
test_some_metric_bucket{le="7.5"} 2
test_some_metric_bucket{le="10"} 2
test_some_metric_bucket{le="+Inf"} 3
test_some_metric_count 3
test_some_metric_sum 22.1

EOF
            )
        );
    }

    /**
     * @test
     */
    public function itShouldIncreaseACounterWithoutNamespace()
    {
        $registry = new CollectorRegistry($this->newRedisAdapter());
        $registry
            ->registerCounter('', 'some_quick_counter', 'just a quick measurement')
            ->inc();

        $this->assertThat(
            $this->renderer->render($registry->getMetricFamilySamples()),
            $this->equalTo(<<<EOF
# HELP some_quick_counter just a quick measurement
# TYPE some_quick_counter counter
some_quick_counter 1

EOF
            )
        );
    }

    private function newRedisAdapter()
    {
        return new Redis(array('host' => REDIS_HOST));
    }
}
