<?php

use Growthbook\Client;
use Growthbook\Experiment;
use Growthbook\TrackData;
use Growthbook\User;
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
   * @param TrackData[] $mock
   */
  private function mockCallback(array &$mock): void {
    $this->client->config->onExperimentViewed = function(TrackData $data) use(&$mock) {
      $mock[] = $data;
    };
  }

  /**
   * @param string|User $user
   * @param string|Experiment $experiment
   */
  private function chooseVariation($user, $experiment): int {
    if(is_string($user)) {
      $user = $this->client->user(["id"=>$user]);
    }
    return $user->experiment($experiment)->variation;
  }

  public function testDefaultWeights(): void {
    $experiment = new Experiment("my-test", 2);

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
    $experiment = new Experiment("my-test", 2, [
      "weights" => [0.1, 0.9]
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
    $experiment = new Experiment("my-test", 2, [
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
    $experiment = new Experiment("my-test", 3);

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
    $this->assertEquals(1, $this->chooseVariation("1", new Experiment("my-test", 2)));
    $this->assertEquals(0, $this->chooseVariation("1", new Experiment("my-test-3", 2)));
  }

  public function testAnonId(): void {
    $userOnly = $this->client->user(["id"=>"1"]);
    $anonOnly = $this->client->user(["anonId"=>"2"]);
    $both = $this->client->user(["id"=>"1", "anonId"=>"2"]);

    $experimentAnon = new Experiment("my-test", 2, ["anon"=>true]);
    $experimentUser = new Experiment("my-test", 2, ["anon"=>false]);

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

    $mock = [];
    $this->mockCallback($mock);

    $user1 = $this->client->user(["id"=>"1"]);
    $user2 = $this->client->user(["id"=>"2"]);

    $experiment1 = new Experiment("my-test", 2);
    $experiment2 = new Experiment("my-other-test", 2);

    $user1->experiment($experiment1);
    $user1->experiment($experiment1);
    $user1->experiment($experiment1);
    $user1->experiment($experiment2);
    $user2->experiment($experiment2);

    $this->assertEquals(3, count($mock));

    $this->assertEquals("1", $mock[0]->user->id);
    $this->assertEquals("my-test", $mock[0]->result->experiment->id);
    $this->assertEquals(1, $mock[0]->result->variation);

    $this->assertEquals("1", $mock[1]->user->id);
    $this->assertEquals("my-other-test", $mock[1]->result->experiment->id);
    $this->assertEquals(1, $mock[1]->result->variation);

    $this->assertEquals("2", $mock[2]->user->id);
    $this->assertEquals("my-other-test", $mock[2]->result->experiment->id);
    $this->assertEquals(0, $mock[2]->result->variation);
  }

  public function testOverride(): void {
    $override = new Experiment("my-test", 2);
    $this->client->setExperimentConfigs([$override]);

    $experiment = new Experiment("my-test", 2);
    $user = $this->client->user(["id"=>"1"]);
    $result = $user->experiment($experiment);

    $this->assertEquals($result->experiment, $override);

    // Reset
    $this->client->setExperimentConfigs([]);
  }

  public function testTargeting(): void {
    $experiment = new Experiment("my-test", 2, [
      "targeting"=> [
        'member = true',
        'age > 18',
        'source ~ (google|yahoo)',
        'name != matt',
        'email !~ ^.*@exclude.com$',
      ]
    ]);

    $attributes = [
      "member"=> true,
      "age"=> 21,
      "source"=> 'yahoo',
      "name"=> 'george',
      "email"=> 'test@example.com',
    ];

    // Matches all
    $user = $this->client->user(["id"=>"1", "attributes"=>$attributes]);
    $this->assertEquals(1, $this->chooseVariation($user, $experiment), "Matches all");

    // Missing negative checks
    $user->setAttributes([
      "member" => true,
      "age" => 21,
      "source" => "yahoo"
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

  public function testExperimentsDisabled(): void {
    $this->client = new Client();
    $mock = [];
    $this->mockCallback($mock);

    $this->client->config->enabled = false;
    $this->assertEquals(-1, $this->chooseVariation("1", new Experiment("my-test", 2)));
    $this->client->config->enabled = true;

    $this->assertEquals(0, count($mock));
  }

  public function testQuerystringForce(): void {
    $this->client = new Client();
    $_GET['forced-test-qs'] = '1';

    $experiment = new Experiment("forced-test-qs", 2);
    $this->assertEquals(0, $this->chooseVariation("1", $experiment));

    $this->client->config->enableQueryStringOverride = true;
    $this->assertEquals(1, $this->chooseVariation("1", $experiment));

    unset($_GET['forced-test-qs']);
  }

  public function testQuerystringDisabledTracking(): void {
    $this->client = new Client();
    $mock = [];
    $this->mockCallback($mock);

    $this->client->config->enableQueryStringOverride = true;
    $_GET["forced-test-qs"] = "1";

    $experiment = new Experiment("forced-test-qs", 2);
    $this->chooseVariation("1", $experiment);

    $this->assertEquals(0, count($mock));

    unset($_GET['forced-test-qs']);
  }

  public function testConfigData(): void {
    $this->client = new Client();
    $user = $this->client->user(["id"=>"1"]);

    $experiment = new Experiment("my-test", 2, [
      "data"=>[
        "color"=>["blue", "green"],
        "size"=>["small", "large"],
      ]
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

  public function testConfigDataLookup(): void {
    $experiment1 = new Experiment("button-color-size-chrome", 2, [
      "targeting" => ["browser = chrome"],
      "data" => [
        "button.color" => ["blue", "green"],
        "button.size" => ["small", "large"]
      ]
    ]);
    $experiment2 = new Experiment("button-color-safari", 2, [
      "targeting" => ["browser = safari"],
      "data" => [
        "button.color" => ["blue", "green"],
      ]
    ]);
    $this->client->setExperimentConfigs([
      $experiment1,
      $experiment2
    ]);

    $user = $this->client->user(["id"=>"1"]);

    // No matches
    $this->assertEquals(null, $user->lookupByDataKey("button.unknown")->value);

    // First matching experiment
    $user->setAttributes([
      "browser"=>"chrome"
    ]);
    $color = $user->lookupByDataKey("button.color");
    $size = $user->lookupByDataKey("button.size");
    $this->assertEquals("blue", $color->value);
    $this->assertEquals("button-color-size-chrome", $color->experiment->id ?? null);
    $this->assertEquals("small", $size->value);
    $this->assertEquals("button-color-size-chrome", $size->experiment->id ?? null);

    // Fallback experiment
    $user->setAttributes([
      "browser"=>"safari"
    ]);
    $color = $user->lookupByDataKey("button.color");
    $this->assertEquals("blue", $color->value);
    $this->assertEquals("button-color-safari", $color->experiment->id ?? null);

    // Fallback undefined
    $size = $user->lookupByDataKey("button.size");
    $this->assertEquals(null, $size->value);
    $this->assertEquals(null, $size->experiment->id ?? null);
  }

  public function testEvenWeighting(): void {
    // Full coverage
    $experiment = new Experiment("my-test", 2);
    $variations = [0,0];
    for($i=0; $i<1000; $i++) {
      $variations[$this->chooseVariation((string) $i, $experiment)]++;
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
}