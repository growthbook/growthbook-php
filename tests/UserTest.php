<?php
require_once __DIR__.'/../vendor/autoload.php';

use Growthbook\Client;
use Growthbook\Experiment;
use Growthbook\TrackData;
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
   * @param string|Experiment $experiment
   */
  private function chooseVariation($user, $experiment): int {

    if(is_string($experiment)) {
      $experiment = new Experiment($experiment);
    }

    if(is_string($user)) {
      $user = $this->client->user(["id"=>$user]);
    }
    return $user->experiment($experiment)->variation;
  }

  public function testClientDefaultOptions(): void {
    $client = new Client();
    $this->assertEquals(true, $client->config->enabled);
    $this->assertEquals(null, $client->config->logger);
    $this->assertEquals(false, $client->config->enableQueryStringOverride);
  }

  public function testDefaultWeights(): void {
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

  public function testOldUserSignature(): void {
    /** @phpstan-ignore-next-line */
    $user = $this->client->user("1");
    /** @phpstan-ignore-next-line */
    $withAttributes = $this->client->user("1", ["hello"=>"world"]);

    $this->assertEquals('1', $user->id);
    $this->assertEquals('1', $user->anonId);
    $this->assertEquals(["hello"=>"world"], $withAttributes->getAttributes());
  }

  public function testUnevenWeights(): void {
    $experiment = new Experiment("my-test",[
      "variations"=>[
        ["weight"=>0.1],
        ["weight"=>0.9]
      ],
    ]);

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

  public function testCoverage(): void {
    $experiment = new Experiment("my-test",[
      "variations"=>2,
      "coverage" => 0.4
    ]);

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

  public function test3WayTest(): void {
    $experiment = new Experiment("my-test",[
      "variations"=>3,
    ]);

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

  public function testExperimentName(): void {
    $this->assertEquals(1, $this->chooseVariation("1", new Experiment("my-test")));
    $this->assertEquals(0, $this->chooseVariation("1", new Experiment("my-test-3")));
  }

  public function testMissingUserId(): void {
    $this->assertEquals(-1, $this->chooseVariation("", new Experiment("my-test")));
  }

  public function testAnonId(): void {
    $userOnly = $this->client->user(["id"=>"1"]);
    $anonOnly = $this->client->user(["anonId"=>"2"]);
    $both = $this->client->user(["id"=>"1", "anonId"=>"2"]);

    $experimentAnon = new Experiment("my-test",[
      "anon" => true
    ]);
    $experimentUser = new Experiment("my-test",[
      "anon" => false
    ]);

    $this->assertEquals(1, $this->chooseVariation($userOnly, $experimentUser));
    $this->assertEquals(1, $this->chooseVariation($both, $experimentUser));
    $this->assertEquals(-1, $this->chooseVariation($anonOnly, $experimentUser));

    $this->assertEquals(-1, $this->chooseVariation($userOnly, $experimentAnon));
    $this->assertEquals(0, $this->chooseVariation($both, $experimentAnon));
    $this->assertEquals(0, $this->chooseVariation($anonOnly, $experimentAnon));
  }

  public function testTracking(): void {
    // Reset client
    $this->client = new Client();

    $user1 = $this->client->user(["id"=>"1"]);
    $user2 = $this->client->user(["id"=>"2"]);

    $experiment1 = new Experiment("my-test");
    $experiment2 = new Experiment("my-other-test");

    $user1->experiment($experiment1);
    $user1->experiment($experiment1);
    $user1->experiment($experiment1);
    $user1->experiment($experiment2);
    $user2->experiment($experiment2);

    $tracks = $this->client->getTrackData();

    $this->assertEquals(3, count($tracks));

    $this->assertEquals("1", $tracks[0]->user->id);
    $this->assertEquals("my-test", $tracks[0]->result->experiment->key ?? null);
    $this->assertEquals(1, $tracks[0]->result->variation);

    $this->assertEquals("1", $tracks[1]->user->id);
    $this->assertEquals("my-other-test", $tracks[1]->result->experiment->key??null);
    $this->assertEquals(1, $tracks[1]->result->variation);

    $this->assertEquals("2", $tracks[2]->user->id);
    $this->assertEquals("my-other-test", $tracks[2]->result->experiment->key??null);
    $this->assertEquals(0, $tracks[2]->result->variation);
  }

  public function testTracksVariationKeys(): void {
    $experiment = new Experiment("my-test",[
      "variations"=>[
        ["key"=>"first"],
        ["key"=>"second"]
      ]
    ]);
    $client = new Client();
    $user = $client->user(["id"=>"1"]);
    $user->experiment($experiment);
    $tracks = $client->getTrackData();
    $this->assertEquals(1, count($tracks));
    
    $track = $tracks[0];
    $this->assertEquals($experiment, $track->result->experiment);
    $this->assertEquals(1, $track->result->variation);
    $this->assertEquals('second', $track->result->variationKey);
  }

  public function testWeirdExperimentValues(): void {
    $experiment = new Experiment("my-test",[
      "variations"=>1
    ]);
    $this->assertEquals(-1, $this->chooseVariation("1", $experiment));

    $this->assertEquals([0.5,0.5], $experiment->getScaledWeights());
    $experiment->variations = 30;
    $this->assertEquals([0.5,0.5], $experiment->getScaledWeights());
    $experiment->variations = 2;
    $experiment->coverage = -0.2;
    $this->assertEquals([0.5,0.5], $experiment->getScaledWeights());
    $experiment->coverage = 1.5;
    $this->assertEquals([0.5,0.5], $experiment->getScaledWeights());
    $experiment->coverage = 1;
    $experiment->variations = [
      ["weight"=>0.4],
      ["weight"=>0.1],
    ];
    $this->assertEquals([0.5,0.5], $experiment->getScaledWeights());
    $experiment->variations = [
      ["weight"=>0.7],
      ["weight"=>0.6],
    ];
    $this->assertEquals([0.5,0.5], $experiment->getScaledWeights());
  }

  // A variation is forced
  public function testForcedVariation(): void {
    $user = $this->client->user(["id"=>"1"]);
    $experiment = new Experiment("my-test");
    $this->assertEquals(1, $this->chooseVariation($user, $experiment));

    $experiment->force = -1;
    $this->assertEquals(-1, $this->chooseVariation($user, $experiment));

    $experiment->force = 0;
    $this->assertEquals(0, $this->chooseVariation($user, $experiment));

    $experiment->force = 1;
    $this->assertEquals(1, $this->chooseVariation($user, $experiment));
  }

  public function testWeirdTargetingRules(): void {
    $this->assertEquals(true, Util::checkRule('9', '<', '20', $this->client));
    $this->assertEquals(false, Util::checkRule('5', '<', '4', $this->client));
    $this->assertEquals(true, Util::checkRule('a', '?', 'b', $this->client));
  }

  public function testOverride(): void {
    $override = new Experiment("my-test");
    $this->client->experiments = [$override];

    $experiment = new Experiment("my-test");
    $user = $this->client->user(["id"=>"1"]);
    $result = $user->experiment($experiment);

    $this->assertEquals($result->experiment, $override);

    // Reset
    $this->client->experiments = [];
  }

  public function testIgnoreOverrideWhenNumVariationsDifferent(): void {
    $override = new Experiment("my-test",[
      "variations"=>[
        ["weight"=>0.5],
        ["weight"=>0.3],
        ["weight"=>0.2]
      ],
    ]);
    $this->client->experiments = [$override];

    $experiment = new Experiment("my-test");
    $user = $this->client->user(["id"=>"1"]);
    $result = $user->experiment($experiment);

    $this->assertEquals($result->experiment, $experiment);

    // Reset
    $this->client->experiments = [];
  }

  public function testTargeting(): void {
    $experiment = new Experiment("my-test",[
      "targeting"=> [
        'member = true',
        'age > 18',
        'source ~ (google|yahoo)',
        'name != matt',
        'email !~ ^.*@exclude.com$',
        'colors !~ brown'
      ]
    ]);

    $attributes = [
      "member"=> true,
      "age"=> 21,
      "source"=> 'yahoo',
      "name"=> 'george',
      "email"=> 'test@example.com',
      "colors" => ["red", "blue", "green"]
    ];

    // Matches all
    $user = $this->client->user(["id"=>"1", "attributes"=>$attributes]);
    $this->assertEquals(1, $this->chooseVariation($user, $experiment), "Matches all");

    // Missing negative checks
    $user->setAttributes([
      "member" => true,
      "age" => 21,
      "source" => "yahoo",
    ]);
    $this->assertEquals(1, $this->chooseVariation($user, $experiment), "Missing negative checks");

    // Missing all attributes
    $user->setAttributes([]);
    $this->assertEquals(-1, $this->chooseVariation($user, $experiment), "Missing all attribtues");

    // Fails boolean
    $user->setAttributes(array_merge($attributes, [
      "member"=> false
    ]));
    $this->assertEquals(-1, $this->chooseVariation($user, $experiment), "Fails boolean");

    // Fails number
    $user->setAttributes(array_merge($attributes, [
      "age"=> 17
    ]));
    $this->assertEquals(-1, $this->chooseVariation($user, $experiment), "Fails number");

    // Fails regex
    $user->setAttributes(array_merge($attributes, [
      "source"=> "goog"
    ]));
    $this->assertEquals(-1, $this->chooseVariation($user, $experiment), "Fails regex");

    // Fails not equals
    $user->setAttributes(array_merge($attributes, [
      "name"=> "matt"
    ]));
    $this->assertEquals(-1, $this->chooseVariation($user, $experiment), "Fails not equals");

    // Fails not regex
    $user->setAttributes(array_merge($attributes, [
      "email"=> "test@exclude.com"
    ]));
    $this->assertEquals(-1, $this->chooseVariation($user, $experiment), "Fails not regex");
  }

  public function testAttributeMerge(): void {
    $user = $this->client->user(["id" => "1", "attributes" => [
      "foo" => 1,
      "bar" => 2
    ]]);
    $this->assertEquals([
      "foo" => 1,
      "bar" => 2,
    ], $user->getAttributes());

    $user->setAttributes([
      "bar" => 3,
      "baz" => 1
    ], true);

    $this->assertEquals([
      "foo" => 1,
      "bar" => 3,
      "baz" => 1
    ], $user->getAttributes());
  }

  public function testExperimentsDisabled(): void {
    $this->client = new Client();

    $this->client->config->enabled = false;

    // Experiments disabled
    $this->assertEquals(-1, $this->chooseVariation("1", new Experiment("my-test")));

    // Feature flags disabled
    $this->client->experiments = [new Experiment("my-test", [
      "variations" => [
        ["data"=>["color"=>"blue"]],
        ["data"=>["color"=>"green"]],
      ]
    ])];
    $result = $this->client->user(["id"=>"1"])->getFeatureFlag("color");
    $this->assertEquals(null, $result->value);

    // Cleanup
    $this->client->config->enabled = true;
    $this->client->experiments = [];

    // Make sure nothing was tracked
    $tracks = $this->client->getTrackData();
    $this->assertEquals(0, count($tracks));
  }

  public function testQuerystringForce(): void {
    $this->client = new Client();
    $_GET['forced-test-qs'] = '1';

    $experiment = new Experiment("forced-test-qs");
    $this->assertEquals(0, $this->chooseVariation("1", $experiment));

    $this->client->config->enableQueryStringOverride = true;
    $this->assertEquals(1, $this->chooseVariation("1", $experiment));

    unset($_GET['forced-test-qs']);
  }

  public function testQuerystringDisabledTracking(): void {
    $this->client = new Client();

    $this->client->config->enableQueryStringOverride = true;
    $_GET["forced-test-qs"] = "1";

    $experiment = new Experiment("forced-test-qs");
    $this->chooseVariation("1", $experiment);

    $tracks = $this->client->getTrackData();
    $this->assertEquals(0, count($tracks));

    unset($_GET['forced-test-qs']);
  }

  public function testUrlTargeting(): void {
    $experiment = new Experiment("my-test",[
      "url" => "^/post/[0-9]+",
    ]);

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

  public function testInvalidUrlRegex(): void {
    $experiment = new Experiment("my-test",[
      "url" => '???***[)',
    ]);

    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['HTTP_HOST'] = 'example.com';
    $_SERVER['REQUEST_URI'] = '/post/123';
    $this->assertEquals(-1, $this->chooseVariation("1", $experiment));
  }

  public function testIgnoreDraftExperiments(): void {
    $this->client->config->enableQueryStringOverride = true;
    $experiment = new Experiment("my-test",[
      "status" => "draft"
    ]);
    $this->assertEquals(-1, $this->chooseVariation("1", $experiment));

    $_GET['my-test'] = '1';
    $this->assertEquals(1, $this->chooseVariation("1", $experiment));

    unset($_GET['my-test']);
    $this->client->config->enableQueryStringOverride = false;
  }

  public function testIgnoresStoppedExperimentsUnlessForced(): void {
    $lose = new Experiment("my-test",[
      "variations" => 3,
      "status" => "stopped"
    ]); 
    $win = new Experiment("my-test",[
      "variations" => 3,
      "status" => "stopped",
      "force" => 2,
    ]);
    $this->assertEquals(-1, $this->chooseVariation("1", $lose));
    $this->assertEquals(2, $this->chooseVariation("1", $win));
  }

  public function testConfigData(): void {
    $this->client = new Client();
    $user = $this->client->user(["id"=>"1"]);

    $experiment = new Experiment("my-test",[
      "variations"=>[
        [
          "data" => ["color"=>"blue", "size"=>"small"]
        ],
        [
          "data" => ["color"=>"green", "size"=>"large"]
        ]
      ],
    ]);

    // Get correct config data
    $result = $user->experiment($experiment);
    $this->assertEquals("green", $result->getData("color"));
    $this->assertEquals("large", $result->getData("size"));

    // Fallback to control config data if not in test
    $experiment->coverage = 0.01;
    $result = $user->experiment($experiment);
    $this->assertEquals("blue", $result->getData("color"));
    $this->assertEquals("small", $result->getData("size"));

    // Null for undefined keys
    $this->assertEquals(null, $result->getData("unknown"));
  }

  // TODO: test fetching configs from api

  public function testFeatureFlagLookup(): void {
    $experiment1 = new Experiment("button-color-size-chrome",[
      "variations"=>[
        [
          "data"=>["button.color"=>"blue","button.size"=>"small"]
        ],
        [
          "data"=>["button.color"=>"green","button.size"=>"large"]
        ]
      ],
      "targeting" => ["browser = chrome"],
    ]);
    $experiment2 = new Experiment("button-color-safari",[
      "variations"=>[
        [
          "data"=>["button.color"=>"blue"]
        ],
        [
          "data"=>["button.color"=>"green"]
        ]
      ],
      "targeting" => ["browser = safari"],
    ]);
    $this->client->experiments = [
      $experiment1,
      $experiment2
    ];

    $user = $this->client->user(["id"=>"1"]);

    // No matches
    $this->assertEquals(null, $user->getFeatureFlag("button.unknown")->value);

    // First matching experiment
    $user->setAttributes([
      "browser"=>"chrome"
    ]);
    $color = $user->getFeatureFlag("button.color");
    $size = $user->getFeatureFlag("button.size");
    $this->assertEquals("blue", $color->value);
    $this->assertEquals("button-color-size-chrome", $color->experiment->key ?? null);
    $this->assertEquals("small", $size->value);
    $this->assertEquals("button-color-size-chrome", $size->experiment->key ?? null);

    // Fallback experiment
    $user->setAttributes([
      "browser"=>"safari"
    ]);
    $color = $user->getFeatureFlag("button.color");
    $this->assertEquals("blue", $color->value);
    $this->assertEquals("button-color-safari", $color->experiment->key ?? null);

    // Fallback undefined
    $size = $user->getFeatureFlag("button.size");
    $this->assertEquals(null, $size->value);
    $this->assertEquals(null, $size->experiment->key ?? null);

    $this->client->experiments = [];
  }

  public function testJSONImport(): void {
    $experiments = '[{
      "key": "json-test",
      "variations": 3
    }]';
    $this->client->experiments = [];
    $this->client->addExperimentsFromJSON($experiments);

    $this->assertEquals(1, count($this->client->experiments));
    $exp = $this->client->experiments[0];
    $this->assertEquals("json-test", $exp->key);
    $this->assertEquals(3, $exp->variations);

    $this->client->experiments = [];
  }

  public function testGetDataFromNullResult(): void {
    $experiment = new Experiment("my-test");
    $user = $this->client->user(["id"=>"1"]);
    $result = $user->experiment($experiment);
    $this->assertEquals(null, $result->getData("color"));

    $result = $user->getFeatureFlag("hello");
    $this->assertEquals(null, $result->getData("hello"));
  }

  public function testEvenWeighting(): void {
    // Full coverage
    $experiment = new Experiment("my-test");
    $variations = [0,0];
    for($i=1; $i<1000; $i++) {
      $v = $this->chooseVariation((string) $i, $experiment);
      $variations[$v]++;
    }

    $this->assertEquals(503, $variations[0]);

    // Reduced coverage
    $experiment->coverage = 0.4;
    $variations = [
      0=>0,
      1=>0,
      -1=>0
    ];
    for($i=0; $i<1000; $i++) {
      $variations[$this->chooseVariation((string) $i, $experiment)]++;
    }
    $this->assertEquals(200, $variations[0]);
    $this->assertEquals(204, $variations[1]);
    $this->assertEquals(596, $variations[-1]);
  }

  public function testLogs(): void {
    $logger = new class extends \Psr\Log\AbstractLogger {
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
      public function log($level, $message, array $context = array()) {
        $this->logs[] = [$level, $message, $context];
      }
    };
    $this->client->config->logger = $logger;
    $this->client->log("debug","foo",["bar"=>1]);
    $this->assertEquals([["debug", "foo", ["bar"=>1]]], $logger->logs);

    $this->client->config->logger = null;
  }
}