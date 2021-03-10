# Growth Book PHP Library

Small utility library to run controlled experiments (i.e. A/B/n tests) in PHP.

![Build Status](https://github.com/growthbook/growthbook-php/workflows/Build/badge.svg)

-  No external dependencies
-  Lightweight and fast
-  No HTTP requests, everything is defined and evaluated locally
-  Advanced user and page targeting
-  Supports feature flag and remote config use cases
-  PHP 7.1+ with 100% test coverage and phpstan on the highest level

## Installation

Growth Book is available on Composer:

`composer require growthbook/growthbook`

## Quick Usage

```php
$client = new Growthbook\Client();

// Logged-in id of the user being experimented on
// Can also use an anonymous id like session (see below)
$user = $client->user(["id"=>"12345"]);

// Put the user in an experiment (default 2-way 50/50 split)
$result = $user->experiment("my-test");

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

## Experiments

As shown above, the simplest experiment you can define only has a single `key` argument.

There are a lot more configuration options you can specify.  Here is a full example showing all the possible options:

```php
new Growthbook\Experiment("my-test", [
  // Number of variations, or an array with more detailed info for each variation
  "variations" => 2,
  // "running" is always active, "draft" is only active during QA. "stopped" is only active when forcing a winning variation
  "status" => "running",
  // What percent of users should be included in the experiment. Float from 0 to 1.
  "coverage" => 1,
  // Users can only be included in this experiment if the current URL matches this regex
  "url" => "/post/[0-9]+",
  // Array of strings if the format "{key} {operator} {value}"
  // Users must pass all of these targeting rules to be included in this experiment
  "targeting" => [
    "age >= 18"
  ],
  // If specified, all users included in the experiment should be forced into the 
  // specified variation (0 is control, 1 is first variation, etc.)
  "force" => 1,
  // If true, use anonymous id for assigning, otherwise use logged-in user id
  "anon" => false,
]);
```

For `variations`, you can either specify a number like above or an array with more detailed info.  The array takes the following format and everything is optional:

```php
$experiment = Growthbook\Experiment("my-test", [
  "variations" => [
    // One array item for each variation
    [
      // An identifier for the variation
      // Defaults to "0" for control, "1" for first variation, etc.
      "key" => "a",
      // Determines traffic split. Float from 0 to 1, weights for all variations must sum to 1.
      // Defaults to an even split between all variations
      "weight" => 0.5,
      // Arbitrary data attached to the variation. Used to parameterize experiments.
      "data" => [
        "color" => "blue"
      ],
    ],
    ...
  ]
]);
```

## Running Experiments

There are 3 different ways to run experiments. You can use more than one of these at a time; choose what makes sense on a case-by-case basis.

### 1. Code Branching

With this approach, you put the user in the experiment and fork your code depending on the assigned variation.

```php
$result = $user->experiment(new Growthbook\Experiment("my-test"));

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

### 2. Parameterization

You can use Parameterization as a cleaner alternative to code branching for simple experiments.

Requirements:
-  Experiment must define `variations` as an array with the `data` property for each variation

Instead of branching, you would extract the data from the chosen variation:

```php
$result = $user->experiment(new Growthbook\Experiment("my-test", [
  "variations" => [
    [
      "data"=>["color" => "blue"]
    ],
    [
      "data"=>["color" => "green"]
    ]
  ]
]));

// Will be either "blue" or "green"
$color = $result->getData("color") ?? "blue";
```

### 3. Feature Flags

Parameterization still requires referencing experiments directly in code.  Using feature flags, you can get some of the same benefits while also keeping your code more maintainable.

Requirements:
-  Experiments must be defined in the client
-  Experiment must define `variations` as an array with the `data` property for each variation
-  Use more descriptive data keys (e.g. `homepage.signup.color` instead of just `color`)

First, add your experiment definitions to the client:

```php
$client->experiments = [
  new Growthbook\Experiment("my-test", [
    "variations" => [
      [
        "data"=>["homepage.signup.color" => "blue"]
      ],
      [
        "data"=>["homepage.signup.color" => "green"]
      ]
    ]
  ])
];
```

Now you can do a lookup based on the data key without knowing about which (if any) experiments are running:

```php
// Will be either "blue" or "green"
$color = $user->getFeatureFlag("homepage.signup.color") ?? "blue";
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
-  **logger** - An optional psr-3 logger instance
-  **url** - The url of the page (defaults to `$_SERVER['REQUEST_URL']` if not set)
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

## Event Tracking

Typically, you'll want to track who sees which experiment so you can analyze the data later.  You can track however you want: insert to a database, use a logger, pass to the front-end for analytics tracking.

```php
$tracks = $client->getTrackData();

$docs = [];
foreach($tracks as $track) {
  $docs[] = [
    "experimentId" => $data->result->experiment->id,
    "variationId" => $data->result->variationKey,
    "userId" => $data->user->id
  ];
}

// TODO: insert into a database, etc.
echo json_encode($docs);
```

## Remote Config

This libraries enables you to load experiment definitions and overrides externally (e.g. from a database or cache).

```php
// JSON-encoded list of experiments from a database
$experiments = '[
  {
    "key": "my-test",
    "variations": [
      {"weight": 0.8},
      {"weight": 0.2}
    ],
    "coverage": 0.3
  }
]';

// Load them into the client
$client->addExperimentsFromJSON($experiments);

// Now, instead of the inline settings (100% coverage, 50/50 split)
// this will use the overrides in the client (30% coverage, 80/20 split)
$result = $user->experiment(new Growthbook\Experiment("my-test"));
```

This is especially powerful when combined with `$user->getFeatureFlag()`. You can instrument your code with feature flag checks once and then run an unlimited number of experiments against them, all without any additional code deploys.

## Using with the Growth Book App

Managing experiments and analyzing results at scale can be complicated, which is why we built the [Growth Book App](https://www.growthbook.io).  It's completely optional, but definitely worth checking out.

-  Document your experiments with screenshots, markdown, and comment threads
-  Connect to your existing data warehouse or analytics tool to automatically fetch results
   -  Currently supports Snowflake, BigQuery, Redshift, Postgres, Mixpanel, GA, and Athena
-  Advanced bayesian statistics and automated data-quality checks (SRM, etc.)
-  Simple and affordable pricing

Integration is super easy:

1.  Create a Growth Book API key - https://docs.growthbook.io/api
2.  Periodically fetch the latest experiment list from the API and cache in Redis/MySQL/etc.
3.  Call `$client->addExperimentsFromJSON($cachedApiResponse);`

Now you can start/stop tests, adjust coverage and variation weights, and apply a winning variation to 100% of traffic, all within the Growth Book App without deploying code changes to your site.