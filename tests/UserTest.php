<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Growthbook\Client;
use Growthbook\Experiment;
use Growthbook\User;
use Growthbook\Util;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    /** @var Client */
    private $client;

    public function __construct()
    {
        parent::__construct();
        $this->client = new Client();
    }

    /**
     * @param string|User $user
     * @param string|Experiment<mixed> $experiment
     * @return int
     */
    private function chooseVariation($user, $experiment): int
    {
        if (is_string($experiment)) {
            $experiment = new Experiment($experiment);
        }

        if (is_string($user)) {
            $user = $this->client->user(["id" => $user]);
        }

        $result = $user->experiment($experiment);

        if (!$result->inExperiment) {
            return -1;
        }
        return $result->variationId;
    }

    public function testClientDefaultOptions(): void
    {
        $client = new Client();
        $this->assertEquals(true, $client->config->enabled);
        $this->assertEquals(null, $client->config->logger);
        $this->assertEquals(false, $client->config->enableQueryStringOverride);
    }

    public function testDefaultWeights(): void
    {
        $experiment = new Experiment("my-test");

        $this->assertEquals(1, $this->chooseVariation("1", $experiment));
        $this->assertEquals(0, $this->chooseVariation("2", $experiment));
        $this->assertEquals(0, $this->chooseVariation("3", $experiment));
        $this->assertEquals(1, $this->chooseVariation("4", $experiment));
        $this->assertEquals(1, $this->chooseVariation("5", $experiment));
        $this->assertEquals(1, $this->chooseVariation("6", $experiment));
        $this->assertEquals(0, $this->chooseVariation("7", $experiment));
        $this->assertEquals(1, $this->chooseVariation("8", $experiment));
        $this->assertEquals(0, $this->chooseVariation("9", $experiment));
    }

    public function testUnevenWeights(): void
    {
        $experiment = new Experiment(
            "my-test",
            [0,1],
            ["weights" => [0.1, 0.9]]
        );

        $this->assertEquals(1, $this->chooseVariation("1", $experiment));
        $this->assertEquals(1, $this->chooseVariation("2", $experiment));
        $this->assertEquals(0, $this->chooseVariation("3", $experiment));
        $this->assertEquals(1, $this->chooseVariation("4", $experiment));
        $this->assertEquals(1, $this->chooseVariation("5", $experiment));
        $this->assertEquals(1, $this->chooseVariation("6", $experiment));
        $this->assertEquals(0, $this->chooseVariation("7", $experiment));
        $this->assertEquals(1, $this->chooseVariation("8", $experiment));
        $this->assertEquals(1, $this->chooseVariation("9", $experiment));
    }

    public function testCoverage(): void
    {
        $experiment = new Experiment("my-test", [0,1], ["coverage" => 0.4]);

        $this->assertEquals(-1, $this->chooseVariation("1", $experiment));
        $this->assertEquals(0, $this->chooseVariation("2", $experiment));
        $this->assertEquals(0, $this->chooseVariation("3", $experiment));
        $this->assertEquals(-1, $this->chooseVariation("4", $experiment));
        $this->assertEquals(-1, $this->chooseVariation("5", $experiment));
        $this->assertEquals(-1, $this->chooseVariation("6", $experiment));
        $this->assertEquals(0, $this->chooseVariation("7", $experiment));
        $this->assertEquals(-1, $this->chooseVariation("8", $experiment));
        $this->assertEquals(1, $this->chooseVariation("9", $experiment));
    }

    public function test3WayTest(): void
    {
        $experiment = new Experiment("my-test", [0,1,2]);

        $this->assertEquals(2, $this->chooseVariation("1", $experiment));
        $this->assertEquals(0, $this->chooseVariation("2", $experiment));
        $this->assertEquals(0, $this->chooseVariation("3", $experiment));
        $this->assertEquals(2, $this->chooseVariation("4", $experiment));
        $this->assertEquals(1, $this->chooseVariation("5", $experiment));
        $this->assertEquals(2, $this->chooseVariation("6", $experiment));
        $this->assertEquals(0, $this->chooseVariation("7", $experiment));
        $this->assertEquals(1, $this->chooseVariation("8", $experiment));
        $this->assertEquals(0, $this->chooseVariation("9", $experiment));
    }

    public function testExperimentName(): void
    {
        $this->assertEquals(1, $this->chooseVariation("1", new Experiment("my-test")));
        $this->assertEquals(0, $this->chooseVariation("1", new Experiment("my-test-3")));
    }

    public function testMissingUserId(): void
    {
        $this->assertEquals(-1, $this->chooseVariation("", new Experiment("my-test")));
    }

    public function testAnonId(): void
    {
        $userOnly = $this->client->user(["id" => "1"]);
        $anonOnly = $this->client->user(["anonId" => "2"]);
        $both = $this->client->user(["id" => "1", "anonId" => "2"]);

        $experimentAnon = new Experiment("my-test", [0,1], ["anon" => true]);
        $experimentUser = new Experiment("my-test", [0,1], ["anon" => false]);

        $this->assertEquals(1, $this->chooseVariation($userOnly, $experimentUser));
        $this->assertEquals(1, $this->chooseVariation($both, $experimentUser));
        $this->assertEquals(-1, $this->chooseVariation($anonOnly, $experimentUser));

        $this->assertEquals(-1, $this->chooseVariation($userOnly, $experimentAnon));
        $this->assertEquals(0, $this->chooseVariation($both, $experimentAnon));
        $this->assertEquals(0, $this->chooseVariation($anonOnly, $experimentAnon));
    }

    public function testRandomizationUnit(): void
    {
        $experiment = new Experiment("my-test", [0,1], ["randomizationUnit" => "companyId"]);

        for ($i=0;$i<10;$i++) {
            $user = $this->client->user([
                "id" => "".$i,
                "companyId" => "1"
            ]);
            $this->assertEquals(1, $this->chooseVariation($user, $experiment));
        }
    }

    public function testGroups(): void
    {
        $user = $this->client->user(["id"=>"123"], [
            "alpha"=>true,
            "beta"=>true,
            "internal"=>false,
            "qa"=>false
        ]);

        $experiment = new Experiment("my-test", [0,1], [
            "groups" => ["internal", "qa"]
        ]);
        $result = $user->experiment($experiment);
        $this->assertEquals(false, $result->inExperiment);

        $experiment = new Experiment("my-test", [0,1], [
            "groups" => ["internal", "qa", "beta"]
        ]);
        $result = $user->experiment($experiment);
        $this->assertEquals(true, $result->inExperiment);

        $experiment = new Experiment("my-test", [0,1], [
        ]);
        $result = $user->experiment($experiment);
        $this->assertEquals(true, $result->inExperiment);
    }

    public function testTracking(): void
    {
        // Reset client
        $this->client = new Client();

        $user1 = $this->client->user(["id" => "1"]);
        $user2 = $this->client->user(["id" => "2"]);

        $experiment1 = new Experiment("my-test");
        $experiment2 = new Experiment("my-other-test");

        $user1->experiment($experiment1);
        $user1->experiment($experiment1);
        $user1->experiment($experiment1);
        $user1->experiment($experiment2);
        $user2->experiment($experiment2);

        $tracks = $this->client->getTrackData();

        $this->assertEquals(3, count($tracks));

        $this->assertEquals("1", $tracks[0]->user->getRandomizationId("id"));
        $this->assertEquals("my-test", $tracks[0]->experiment->key ?? null);
        $this->assertEquals(1, $tracks[0]->result->variationId);

        $this->assertEquals("1", $tracks[1]->user->getRandomizationId("id"));
        $this->assertEquals("my-other-test", $tracks[1]->experiment->key ?? null);
        $this->assertEquals(1, $tracks[1]->result->variationId);

        $this->assertEquals("2", $tracks[2]->user->getRandomizationId("id"));
        $this->assertEquals("my-other-test", $tracks[2]->experiment->key ?? null);
        $this->assertEquals(0, $tracks[2]->result->variationId);
    }

    public function testTracksVariationKeys(): void
    {
        $experiment = new Experiment("my-test", ["first","second"]);
        $client = new Client();
        $user = $client->user(["id" => "1"]);
        $user->experiment($experiment);
        $tracks = $client->getTrackData();
        $this->assertEquals(1, count($tracks));

        $track = $tracks[0];
        $this->assertEquals($experiment->key, $track->experiment->key);
        $this->assertEquals(1, $track->result->variationId);
        $this->assertEquals('second', $track->result->value);
    }

    public function testNumVariationsTooSmall(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Experiment("my-test", [0]);
    }
    public function testNumVariationsTooBig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Experiment("my-test", range(0, 30));
    }

    public function testWeirdExperimentValues(): void
    {
        $experiment = new Experiment("my-test", [0,1], ["coverage" => -0.2]);
        $this->assertEquals([0.5, 0.5], $experiment->getScaledWeights());
        $experiment->coverage = 1.5;
        $this->assertEquals([0.5, 0.5], $experiment->getScaledWeights());
        $experiment->coverage = 1;
        $experiment->weights = [0.4, 0.1];
        $this->assertEquals([0.5, 0.5], $experiment->getScaledWeights());
        $experiment->weights = [0.7, 0.6];
        $this->assertEquals([0.5, 0.5], $experiment->getScaledWeights());
        $experiment->weights = [0.33,0.33,0.34];
        $this->assertEquals([0.5, 0.5], $experiment->getScaledWeights());
    }

    // A variation is forced
    public function testForcedVariation(): void
    {
        $user = $this->client->user(["id" => "1"]);
        $experiment = new Experiment("my-test");
        $this->assertEquals(1, $this->chooseVariation($user, $experiment));

        $experiment->force = -1;
        $this->assertEquals(-1, $this->chooseVariation($user, $experiment));

        $experiment->force = 0;
        $this->assertEquals(0, $this->chooseVariation($user, $experiment));

        $experiment->force = 1;
        $this->assertEquals(1, $this->chooseVariation($user, $experiment));
    }

    public function testExperimentsDisabled(): void
    {
        $this->client = new Client();

        $this->client->config->enabled = false;

        // Experiments disabled
        $this->assertEquals(-1, $this->chooseVariation("1", new Experiment("my-test")));

        // Cleanup
        $this->client->config->enabled = true;
        $this->client->clearExperimentOverrides();

        // Make sure nothing was tracked
        $tracks = $this->client->getTrackData();
        $this->assertEquals(0, count($tracks));
    }

    public function testQuerystringForce(): void
    {
        $this->client = new Client();
        $_GET['forced-test-qs'] = '1';

        $experiment = new Experiment("forced-test-qs");
        $this->assertEquals(0, $this->chooseVariation("1", $experiment));

        $this->client->config->enableQueryStringOverride = true;
        $this->assertEquals(1, $this->chooseVariation("1", $experiment));

        unset($_GET['forced-test-qs']);
    }

    public function testQuerystringDisabledTracking(): void
    {
        $this->client = new Client();

        $this->client->config->enableQueryStringOverride = true;
        $_GET["forced-test-qs"] = "1";

        $experiment = new Experiment("forced-test-qs");
        $this->chooseVariation("1", $experiment);

        $tracks = $this->client->getTrackData();
        $this->assertEquals(0, count($tracks));

        unset($_GET['forced-test-qs']);
    }

    public function testUrlTargeting(): void
    {
        $experiment = new Experiment("my-test", [0,1], ["url" => "^/post/[0-9]+",]);

        $_SERVER['REQUEST_URI'] = '/';
        $this->assertEquals(-1, $this->chooseVariation("1", $experiment));

        $_SERVER['REQUEST_URI'] = '/post/123';
        $this->assertEquals(1, $this->chooseVariation("1", $experiment));

        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $experiment->url = 'http://example2.com/post/[0-9]+';
        $this->assertEquals(-1, $this->chooseVariation("1", $experiment));
        $experiment->url = 'http://example.com/post/[0-9]+';
        $this->assertEquals(1, $this->chooseVariation("1", $experiment));
    }

    public function testInvalidUrlRegex(): void
    {
        $experiment = new Experiment("my-test", [0,1], ["url" => '???***[)',]);

        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['REQUEST_URI'] = '/post/123';
        $this->assertEquals(-1, $this->chooseVariation("1", $experiment));
    }

    public function testIgnoreDraftExperiments(): void
    {
        $this->client->config->enableQueryStringOverride = true;
        $experiment = new Experiment("my-test", [0,1], ["status" => "draft"]);
        $this->assertEquals(-1, $this->chooseVariation("1", $experiment));

        $_GET['my-test'] = '1';
        $this->assertEquals(1, $this->chooseVariation("1", $experiment));

        unset($_GET['my-test']);
        $this->client->config->enableQueryStringOverride = false;
    }

    public function testIgnoresStoppedExperimentsUnlessForced(): void
    {
        $lose = new Experiment("my-test", [0,1,2], ["status" => "stopped"]);
        $win = new Experiment("my-test", [0,1,2], ["status" => "stopped","force" => 2,]);
        $this->assertEquals(-1, $this->chooseVariation("1", $lose));
        $this->assertEquals(2, $this->chooseVariation("1", $win));
    }

    public function testJSONImport(): void
    {
        $overrides = json_decode('{"json-test":{"coverage": 0.01}}', true);
        $this->client->importOverrides($overrides);

        $this->assertEquals(-1, $this->chooseVariation("1", "json-test"));

        $this->client->clearExperimentOverrides();
    }

    public function testConfigData(): void
    {
        $user = $this->client->user(["id" => "1"]);
        $experiment = new Experiment(
            "my-test",
            [
                ["color" => "blue","size" => "small"],
                ["color" => "green","size" => "large"]
            ]
        );

        $res1 = $user->experiment($experiment);
        $this->assertEquals(1, $res1->variationId);
        $this->assertEquals(["color" => 'green', 'size' => 'large'], $res1->value);

        // Fallback to control config data if not in test
        $experiment->coverage = 0.01;
        $res2 = $user->experiment($experiment);
        $this->assertEquals(false, $res2->inExperiment);
        $this->assertEquals(0, $res2->variationId);
        $this->assertEquals(["color" => 'blue', 'size' => 'small'], $res2->value);
    }

    public function testEvenWeighting(): void
    {
        // Full coverage
        $experiment = new Experiment("my-test");
        $variations = [0, 0];
        for ($i = 1; $i < 1000; $i++) {
            $v = $this->chooseVariation((string) $i, $experiment);
            $variations[$v]++;
        }

        $this->assertEquals(503, $variations[0]);

        // Reduced coverage
        $experiment->coverage = 0.4;
        $variations = [0 => 0, 1 => 0, -1 => 0];
        for ($i = 0; $i < 1000; $i++) {
            $variations[$this->chooseVariation((string) $i, $experiment)]++;
        }
        $this->assertEquals(200, $variations[0]);
        $this->assertEquals(204, $variations[1]);
        $this->assertEquals(596, $variations[-1]);
    }

    public function testDeprecatedIntegerVariations(): void
    {
        set_error_handler(
            static function ($errno, $errstr) {
                restore_error_handler();
                throw new Exception($errstr, $errno);
            },
            E_ALL
        );

        $user = $this->client->user(["id"=>"1"]);
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Passing an integer is deprecated/i');
        $user->experiment(new Experiment("my-test", 2));

        restore_error_handler();
    }

    public function testLogs(): void
    {
        $logger = new class () extends \Psr\Log\AbstractLogger {
            /** @var mixed[] */
            public $logs = [];

            /**
             * Logs with an arbitrary level.
             *
             * @param mixed   $level
             * @param string  $message
             * @param mixed[] $context
             *
             * @return void
             *
             * @throws \Psr\Log\InvalidArgumentException
             */
            public function log($level, $message, array $context = array()):void
            {
                $this->logs[] = [$level, $message, $context];
            }
        };
        $this->client->config->logger = $logger;
        $this->client->log("debug", "foo", ["bar" => 1]);
        $this->assertEquals([["debug", "foo", ["bar" => 1]]], $logger->logs);

        $this->client->config->logger = null;
    }
}
