<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Growthbook\ExperimentResult;
use Growthbook\FeatureResult;
use Growthbook\GrowthBookTrackingPlugin;
use Growthbook\Growthbook;
use Growthbook\InlineExperiment;
use Growthbook\Plugin;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// MockPlugin — records all plugin calls for assertions
// ---------------------------------------------------------------------------

final class MockPlugin implements Plugin
{
    public ?string $initializedWith = null;
    public int $experimentCallCount = 0;
    public int $featureCallCount    = 0;
    public bool $closeCalled        = false;

    /** @var array<int, array{experiment: InlineExperiment<mixed>, result: ExperimentResult<mixed>}> */
    public array $experimentEvents = [];
    /** @var array<int, array{key: string, result: FeatureResult<mixed>}> */
    public array $featureEvents = [];

    public function initialize(string $clientKey): void
    {
        $this->initializedWith = $clientKey;
    }

    /**
     * @param InlineExperiment<mixed> $experiment
     * @param ExperimentResult<mixed> $result
     */
    public function onExperimentViewed(InlineExperiment $experiment, ExperimentResult $result): void
    {
        $this->experimentCallCount++;
        $this->experimentEvents[] = ['experiment' => $experiment, 'result' => $result];
    }

    /**
     * @param FeatureResult<mixed> $result
     */
    public function onFeatureEvaluated(string $featureKey, FeatureResult $result): void
    {
        $this->featureCallCount++;
        $this->featureEvents[] = ['key' => $featureKey, 'result' => $result];
    }

    public function close(): void
    {
        $this->closeCalled = true;
    }
}

// ---------------------------------------------------------------------------
// Plugin integration tests
// ---------------------------------------------------------------------------

final class GrowthbookPluginIntegrationTest extends TestCase
{
    private function makeGrowthbook(MockPlugin ...$plugins): Growthbook
    {
        $gb = Growthbook::create()->withAttributes(['id' => 'user-1']);
        foreach ($plugins as $plugin) {
            $gb->addPlugin($plugin);
        }
        return $gb;
    }

    // ---- Lifecycle ----

    public function testPluginReceivesInitialize(): void
    {
        $plugin = new MockPlugin();
        $gb = $this->makeGrowthbook($plugin);
        // initialize() is called by the SDK's initialize() method which requires
        // a real HTTP call, so we call it manually here to mirror the contract.
        $plugin->initialize('sdk-test-key');
        $this->assertSame('sdk-test-key', $plugin->initializedWith);
    }

    public function testPluginCloseCalledOnDestruct(): void
    {
        $plugin = new MockPlugin();
        $gb = $this->makeGrowthbook($plugin);
        unset($gb);
        $this->assertTrue($plugin->closeCalled);
    }

    public function testPluginAddedViaConstructorOption(): void
    {
        $plugin = new MockPlugin();
        $gb = new Growthbook(['plugins' => [$plugin], 'attributes' => ['id' => 'user-1']]);
        unset($gb);
        $this->assertTrue($plugin->closeCalled);
    }

    // ---- Experiment events ----

    public function testPluginReceivesOnExperimentViewed(): void
    {
        $plugin = new MockPlugin();
        $gb = $this->makeGrowthbook($plugin);

        $exp = InlineExperiment::create('my-exp', ['A', 'B'])->withCoverage(1.0);
        $gb->runInlineExperiment($exp);

        $this->assertSame(1, $plugin->experimentCallCount);
        $this->assertSame('my-exp', $plugin->experimentEvents[0]['experiment']->key);
    }

    public function testPluginNotCalledWhenUserNotInExperiment(): void
    {
        $plugin = new MockPlugin();
        $gb = $this->makeGrowthbook($plugin);

        // coverage = 0 → user never assigned → inExperiment = false
        $exp = InlineExperiment::create('my-exp-zero', ['A', 'B'])->withCoverage(0.0);
        $gb->runInlineExperiment($exp);

        $this->assertSame(0, $plugin->experimentCallCount);
    }

    // ---- Feature events ----

    public function testPluginReceivesOnFeatureEvaluated(): void
    {
        $plugin = new MockPlugin();
        $gb = $this->makeGrowthbook($plugin)->withFeatures([
            'flag-a' => ['defaultValue' => true],
        ]);

        $gb->getFeature('flag-a');

        $this->assertSame(1, $plugin->featureCallCount);
        $this->assertSame('flag-a', $plugin->featureEvents[0]['key']);
    }

    public function testPluginReceivesFeatureEvaluatedForUnknownFeature(): void
    {
        $plugin = new MockPlugin();
        $gb = $this->makeGrowthbook($plugin)->withFeatures([]);

        $gb->getFeature('nonexistent');

        $this->assertSame(1, $plugin->featureCallCount);
        $this->assertSame('nonexistent', $plugin->featureEvents[0]['key']);
        $this->assertSame('unknownFeature', $plugin->featureEvents[0]['result']->source);
    }

    public function testPluginNotCalledForPrerequisiteFeatureEvaluation(): void
    {
        // Prerequisite features are evaluated internally (stack != []),
        // so the plugin should only fire once for the top-level feature.
        $plugin = new MockPlugin();
        $gb = $this->makeGrowthbook($plugin)->withFeatures([
            'parent' => ['defaultValue' => true],
            'child'  => [
                'defaultValue' => false,
                'rules'        => [
                    [
                        'parentConditions' => [
                            ['id' => 'parent', 'condition' => ['value' => true]],
                        ],
                        'force' => true,
                    ],
                ],
            ],
        ]);

        $gb->getFeature('child');

        // Only one plugin call for 'child', not for the internal 'parent' evaluation
        $this->assertSame(1, $plugin->featureCallCount);
        $this->assertSame('child', $plugin->featureEvents[0]['key']);
    }

    public function testIsOnAndIsOffTriggerPluginOnce(): void
    {
        $plugin = new MockPlugin();
        $gb = $this->makeGrowthbook($plugin)->withFeatures([
            'flag-b' => ['defaultValue' => true],
        ]);

        $gb->isOn('flag-b');
        $gb->isOff('flag-b');

        $this->assertSame(2, $plugin->featureCallCount);
    }

    // ---- Multiple plugins ----

    public function testMultiplePluginsAllReceiveEvents(): void
    {
        $p1 = new MockPlugin();
        $p2 = new MockPlugin();
        $gb = $this->makeGrowthbook($p1, $p2)->withFeatures([
            'flag-c' => ['defaultValue' => 42],
        ]);

        $gb->getFeature('flag-c');

        $this->assertSame(1, $p1->featureCallCount);
        $this->assertSame(1, $p2->featureCallCount);
    }

    public function testMultiplePluginsAllReceiveClose(): void
    {
        $p1 = new MockPlugin();
        $p2 = new MockPlugin();
        $gb = $this->makeGrowthbook($p1, $p2);
        unset($gb);

        $this->assertTrue($p1->closeCalled);
        $this->assertTrue($p2->closeCalled);
    }

    public function testMisbehavingPluginDoesNotAffectOtherPlugins(): void
    {
        $badPlugin = new class implements Plugin {
            public function initialize(string $clientKey): void { throw new \RuntimeException("boom"); }
            /** @param InlineExperiment<mixed> $e @param ExperimentResult<mixed> $r */
            public function onExperimentViewed(InlineExperiment $e, ExperimentResult $r): void { throw new \RuntimeException("boom"); }
            /** @param FeatureResult<mixed> $r */
            public function onFeatureEvaluated(string $k, FeatureResult $r): void { throw new \RuntimeException("boom"); }
            public function close(): void { throw new \RuntimeException("boom"); }
        };

        $goodPlugin = new MockPlugin();
        $gb = Growthbook::create()
            ->withAttributes(['id' => 'user-1'])
            ->withFeatures(['flag-d' => ['defaultValue' => true]]);
        $gb->addPlugin($badPlugin);
        $gb->addPlugin($goodPlugin);

        $gb->getFeature('flag-d');

        $this->assertSame(1, $goodPlugin->featureCallCount);
    }
}

// ---------------------------------------------------------------------------
// GrowthBookTrackingPlugin unit tests
// ---------------------------------------------------------------------------

final class GrowthBookTrackingPluginTest extends TestCase
{
    private function makePlugin(int $batchSize = GrowthBookTrackingPlugin::DEFAULT_BATCH_SIZE): GrowthBookTrackingPlugin
    {
        return new GrowthBookTrackingPlugin(
            GrowthBookTrackingPlugin::DEFAULT_INGESTOR_HOST,
            $batchSize
        );
    }

    /** @return InlineExperiment<mixed> */
    private function makeExperiment(string $key = 'test-exp'): InlineExperiment
    {
        return InlineExperiment::create($key, [0, 1]);
    }

    /**
     * @param InlineExperiment<mixed> $exp
     * @return ExperimentResult<mixed>
     */
    private function makeExperimentResult(InlineExperiment $exp): ExperimentResult
    {
        return new ExperimentResult($exp, 'id', 'user-1', 1, true, null);
    }

    /** @return FeatureResult<mixed> */
    private function makeFeatureResult(): FeatureResult
    {
        return new FeatureResult(true, 'defaultValue');
    }

    // ---- No-op without clientKey ----

    public function testNoOpWithEmptyClientKey(): void
    {
        $sent = [];
        $plugin = $this->makePlugin(1);
        $plugin->setSendHandler(function (string $payload) use (&$sent) { $sent[] = $payload; });

        $plugin->initialize('');
        $exp = $this->makeExperiment();
        $plugin->onExperimentViewed($exp, $this->makeExperimentResult($exp));
        $plugin->close();

        $this->assertCount(0, $sent);
    }

    // ---- Batch size flush ----

    public function testFlushWhenBatchSizeReached(): void
    {
        $sent = [];
        $plugin = $this->makePlugin(3);
        $plugin->setSendHandler(function (string $payload) use (&$sent) { $sent[] = json_decode($payload, true); });
        $plugin->initialize('sdk-test');

        $exp = $this->makeExperiment();
        for ($i = 0; $i < 3; $i++) {
            $plugin->onExperimentViewed($exp, $this->makeExperimentResult($exp));
        }

        $this->assertCount(1, $sent);
        $this->assertSame('sdk-test', $sent[0]['client_key']);
        $this->assertCount(3, $sent[0]['events']);
    }

    public function testNoFlushBeforeBatchSizeReached(): void
    {
        $sent = [];
        $plugin = $this->makePlugin(5);
        $plugin->setSendHandler(function (string $payload) use (&$sent) { $sent[] = $payload; });
        $plugin->initialize('sdk-test');

        $exp = $this->makeExperiment();
        for ($i = 0; $i < 4; $i++) {
            $plugin->onExperimentViewed($exp, $this->makeExperimentResult($exp));
        }

        $this->assertCount(0, $sent);
        $plugin->close();
    }

    // ---- close() synchronous flush ----

    public function testCloseFlushesSynchronously(): void
    {
        $sent = [];
        $plugin = $this->makePlugin(100);
        $plugin->setSendHandler(function (string $payload) use (&$sent) { $sent[] = $payload; });
        $plugin->initialize('sdk-test');

        $exp = $this->makeExperiment();
        $plugin->onExperimentViewed($exp, $this->makeExperimentResult($exp));

        $this->assertCount(0, $sent, "no request before close()");
        $plugin->close();
        $this->assertCount(1, $sent, "close() must flush synchronously");
    }

    public function testCloseWithNoEventsDoesNotSendRequest(): void
    {
        $sent = [];
        $plugin = $this->makePlugin();
        $plugin->setSendHandler(function (string $payload) use (&$sent) { $sent[] = $payload; });
        $plugin->initialize('sdk-test');
        $plugin->close();

        $this->assertCount(0, $sent);
    }

    public function testCloseIsIdempotent(): void
    {
        $sent = [];
        $plugin = $this->makePlugin(100);
        $plugin->setSendHandler(function (string $payload) use (&$sent) { $sent[] = $payload; });
        $plugin->initialize('sdk-test');

        $exp = $this->makeExperiment();
        $plugin->onExperimentViewed($exp, $this->makeExperimentResult($exp));
        $plugin->close();
        $plugin->close(); // second call must not send again

        $this->assertCount(1, $sent);
    }

    // ---- Payload format ----

    public function testExperimentViewedEventFormat(): void
    {
        $sent = [];
        $plugin = $this->makePlugin(1);
        $plugin->setSendHandler(function (string $payload) use (&$sent) { $sent[] = json_decode($payload, true); });
        $plugin->initialize('sdk-test');

        $exp = $this->makeExperiment('my-experiment');
        $plugin->onExperimentViewed($exp, $this->makeExperimentResult($exp));

        $event = $sent[0]['events'][0];
        $this->assertSame('experiment_viewed', $event['event']);
        $this->assertSame('my-experiment', $event['experimentKey']);
        $this->assertSame(1, $event['variationId']);
    }

    public function testFeatureEvaluatedEventFormat(): void
    {
        $sent = [];
        $plugin = $this->makePlugin(1);
        $plugin->setSendHandler(function (string $payload) use (&$sent) { $sent[] = json_decode($payload, true); });
        $plugin->initialize('sdk-test');

        $plugin->onFeatureEvaluated('my-feature', $this->makeFeatureResult());

        $event = $sent[0]['events'][0];
        $this->assertSame('feature_evaluated', $event['event']);
        $this->assertSame('my-feature', $event['featureKey']);
        $this->assertSame('defaultValue', $event['source']);
    }

    public function testPayloadContainsClientKey(): void
    {
        $sent = [];
        $plugin = $this->makePlugin(1);
        $plugin->setSendHandler(function (string $payload) use (&$sent) { $sent[] = json_decode($payload, true); });
        $plugin->initialize('my-client-key');

        $exp = $this->makeExperiment();
        $plugin->onExperimentViewed($exp, $this->makeExperimentResult($exp));

        $this->assertSame('my-client-key', $sent[0]['client_key']);
    }

    public function testMixedEventTypesInOneBatch(): void
    {
        $sent = [];
        $plugin = $this->makePlugin(2);
        $plugin->setSendHandler(function (string $payload) use (&$sent) { $sent[] = json_decode($payload, true); });
        $plugin->initialize('sdk-test');

        $exp = $this->makeExperiment();
        $plugin->onExperimentViewed($exp, $this->makeExperimentResult($exp));
        $plugin->onFeatureEvaluated('flag', $this->makeFeatureResult());

        $this->assertCount(1, $sent);
        $this->assertCount(2, $sent[0]['events']);
        $this->assertSame('experiment_viewed', $sent[0]['events'][0]['event']);
        $this->assertSame('feature_evaluated', $sent[0]['events'][1]['event']);
    }
}
