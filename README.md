![Build Status](https://github.com/growthbook/growthbook-php/workflows/Build/badge.svg)

Small utility library to run controlled experiments (i.e. AB tests). Comaptible with the Growth Book experimentation platform.

## Installation

Growth Book is available on Composer:

`composer require growthbook/growthbook`

## Quick Usage

```php
$client = new Growthbook\Client();

// Logged-in id of the user being experimented on
// Can also use an anonymous id like session (see below)
$user = $client->user(["id"=>"12345"]);

// 2 variations, 50/50 split
$experiment = new Growthbook\Experiment("experiment-id", 2);

$result = $user->experiment($experiment);

if($result->variation === 0) {
  echo "Control";
}
elseif($result->variation === 1) {
  echo "Variation";
}
else {
  echo "Not in experiment";
}
```

## Client Configuration

The `Growthbook\Client` constructor takes an optional config arugment:

```php
$config = new Growthbook\Config([
  // options go here
]);
$client = new Growthbook\Client($config);
```

The `Growthbook\Config` constructor takes an associative array of options. Below are all of the available options currently:

-  **enabled** - Default true. Set to false to completely disable all experiments.
-  **onExperimentViewed** - Callback when the user views an experiment. Passed a `Growthbook\TrackData` object with experiment, variation, and user info.
-  **enableQueryStringOverride** - Default false.  If true, enables forcing variations via the URL.  Very useful for QA.  https://example.com/?my-experiment=1

You can change configuration options at any time by setting properties directly:

```php
$client->config->enabled = false;
```

## User Configuration

The `$client->user` method takes a single associative array argument.  There are 3 possible keys you can use:

-  `id` - The logged-in user id
-  `anonId` - An anonymous identifier for the user (session id, cookie, ip, etc.)
-  `attributes` - An associative array with user attributes. These are never sent across the network and are only used to locally evaluate experiment targeting rules.

Although all of these are technically optional, at least 1 type of id must be set or the user will be excluded from all experiments.

Here is an example that uses all 3 properties:

```php
$user = $client->user([
  // Logged-in user id
  "id"=>"12345",

  // Anonymous id
  "anonId"=>"abcdef",

  // Targeting attributes
  "attributes"=> [
    "premium" => true,
    "accountAge" => 36,
    "geo" => [
      "region" => "NY"
    ]
  ]
]);
```

You can update attributes at any time by calling `$user->setAttributes`. By default, this completely overwrites all previous attributes. To do a shallow merge instead, pass `true` as the 2nd argument.

```php
// Only overwrite the "premium" key and keep all the others
$user->setAttributes([
    "premium" => false
], true);
```

## Experiment Configuration

The default test is a 50/50 split with no targeting or customization.  There are a few ways to configure this on a test-by-test basis.

### Option 1: Global Configuration

With this option, you configure all experiments globally once and then reference them via id throughout the code.

```php
// Build array of Growthbook\Experiment objects
$experiments = [
  // Default 50/50 2-way test
  new Growthbook\Experiment("my-test", 2),

  // Changing some options
  new Growthbook\Experiment("my-other-test", 2, [
    // Only run on 40% of traffic
    "coverage" => 0.4,
    // 80/20 traffic split between the variations
    "weights" => [0.8, 0.2]
  ])
];

$client->setExperimentConfigs($experiments);

// Later in code, pass the string id instead of the Experiment object
$result = $user->experiment("my-test");
```

Instead of building the array of experiments manually, there's a helper method to fetch the latest configs from the Growth Book API:

```php
// Optional 2nd argument with guzzle config settings
$experiments = $client->fetchExperimentConfigs("my-api-key");
$client->setExperimentConfigs($experiments);
```

This does a network request to the Growth Book CDN. The CDN is very fast and reliable, but we still recommend implementing a caching layer (Memcached, Redis, APCu, DynamoDB, etc.) if possible.

### Option 2: Inline Experiment Configuration

As shown in the quick start above, you can use a `Growthbook\Experiment` object directly to run an experiment.

The below example shows all of the possible experiment options you can set:
```php
// 1st argument is the experiment id
// 2nd argument is the number of variations
$experiment = new Growthbook\Experiment("my-experiment-id", 3, [
    // Percent of traffic to include in the test (from 0 to 1)
    "coverage" => 0.5,
    // How to split traffic between variations (must add to 1)
    "weights" => [0.34, 0.33, 0.33],
    // If false, use the logged-in user id to assign variations
    // If true, use the anonymous id
    "anon" => false,
    // Targeting rules
    // Evaluated against user attributes to determine who is included in the test
    "targeting" => ["source != google"],
    // Add arbitrary data to the variations (see below for more info)
    "data" => [
        "color" => ["blue","green","red"]
    ]
]);

$result = $user->experiment($experiment);
```

## Running Experiments

Growth Book supports 3 different implementation approaches:

1.  Branching
2.  Parameterization
3.  Config System

### Approach 1: Branching

This is the simplest to understand and implement. You add branching via if/else or switch statements:

```php
$result = $user->experiment("experiment-id");

if($result->variation === 1) {
    // Variation
    $buttonColor = "green";
}
else {
    // Control
    $buttonColor = "blue";
}
```

### Approach 2: Parameterization

With this approach, you parameterize the variations by associating them with data.

```php
$experiment = new Growthbook\Experiment("experiment-id", 2, [
  "data" => [
    "color" => ["blue", "green"]
  ]
]);

$result = $user->experiment($experiment);

// Will be either "blue" or "green"
$buttonColor = $result->getData("color");

// If no data is defined for the key, `null` is returned
$result->getData("unknown");
```

### Approach 3: Configuration System

If you already have an existing configuration or feature flag system, you can do a deeper integration that 
avoids `experiment` calls throughout your code base entirely.

All you need to do is modify your existing config system to get experiment overrides before falling back to your normal lookup process:

```php
// Your existing function
function getConfig($key) {
    // Look for a valid matching experiment. 
    // If found, choose a variation and return the value for the requested key
    $result = $user->lookupByDataKey($key);
    if($result->value !== null) {
        return $result->value;
    }

    // Continue with your normal lookup process
    ...
}
```

Instead of generic keys like `color`, you probably want to be more descriptive with this approach (e.g. `homepage.cta.color`).

With the following experiment data:
```php
[
  "data" => [
    "homepage.cta.color" => ["blue", "green"]
  ]
]
```

You can now do:

```php
$buttonColor = getConfig("homepage.cta.color");
```

Your code now no longer cares where the value comes from. It could be a hard-coded config value or part of an experiment.  This is the cleanest approach of the 3, but it can be difficult to debug if things go wrong.

## Tracking

The Growth Book library does not do any event tracking.  You must implement that yourself.  There are 2 tracking methods:

### Tracking Method 1: Callback

You can configure a callback to be fired synchronously when a user is assigned a variation.

Use this for logging debug info.

```php
$client->config->onExperimentViewed = function(Growthbook\TrackData $data) {
  $info = [
    "experimentId" => $data->result->experiment->id,
    "variation" => $data->result->variation,
    "userId" => $data->user->id
  ];
  print_r($info);
}
```

### Tracking Method 2: Array

At the end of your script, you can loop through an array of all `Growthbook\TrackData` objects.

Use this to more efficiently write to a database in bulk or pass to the front-end for client-side event tracking (Segment, GA, etc.).

```php
$tracks = $client->getTrackData();

$docs = [];
foreach($tracks as $track) {
  $docs[] = [
    "experimentId" => $data->result->experiment->id,
    "variation" => $data->result->variation,
    "userId" => $data->user->id
  ];
}

echo json_encode($docs);
```