<p align="center"><img src="https://www.growthbook.io/logos/growthbook-logo@2x.png" width="400px" /></p>

# Growth Book - PHP

Powerful A/B testing for PHP.

![Build Status](https://github.com/growthbook/growthbook-php/workflows/Build/badge.svg)

-  **No external dependencies**
-  **Lightweight and fast**
-  **No HTTP requests** everything is defined and evaluated locally
-  **PHP 7.1+** with 100% test coverage and phpstan on the highest level
-  **Advanced user and page targeting**
-  **Use your existing event tracking** (GA, Segment, Mixpanel, custom)
-  **Adjust variation weights and targeting** without deploying new code

## Installation

Growth Book is available on Composer:

`composer require growthbook/growthbook`

## Quick Usage

```php
$client = new Growthbook\Client();

// Define the user that you want to run an experiment on
$user = $client->user(["id"=>"12345"]);

// Define the experiment
$experiment = new Growthbook\Experiment("my-experiment", ["A", "B"]);

// Put the user in the experiment
$result = $user->experiment($experiment);

echo $result->value; // "A" or "B"
```

At the end of the request, you would track all of the viewed experiments in your analytics tool or database (Segment, GA, Mixpanel, etc.).

```php
$impressions = $client->getViewedExperiments();
foreach($impressions as $impression) {
  // Whatever you use for event tracking
  Segment::track([
    "userId" => $impression->userId,
    "event" => "Experiment Viewed",
    "properties" => [
      "experimentId" => $impression->experimentId,
      "variationId" => $impression->variationId
    ]
  ])
}
```

## Experiments

As shown above, the simplest experiment you can define has an id and an array of variations.

There is an optional 3rd argument, which is an associative array of additional options:

-  **weights** (`float[]`) - How to weight traffic between variations. Must add to 1 and be the same length as the number of variations.
-  **status** (`string`) - "running" is the default and always active. "draft" is only active during QA and development.  "stopped" is only active when forcing a winning variation to 100% of users.
-  **coverage** (`float`) - What percent of users should be included in the experiment (between 0 and 1, inclusive)
-  **url** (`string`) - Users can only be included in this experiment if the current URL matches this regex
-  **targeting** (`string[]`) - Users must pass all of these targeting rules to be included in this experiment (see below for more info)
-  **force** (`int`) - All users included in the experiment will be forced into the specific variation index
-  **anon** (`bool`) - If true, use anonymous id for assigning, otherwise use logged-in user id.  Defaults to false.

## Running Experiments

Run experiments by calling `$user->experiment()` which returns an object with a few useful properties:

```php
$result = $user->experiment(new Growthbook\Experiment(
  "my-experiment", ["A", "B"]
);

// If user is part of the experiment
echo $result->inExperiment; // true or false

// The index of the assigned variation
echo $result->variationId; // 0 or 1

// The value of the assigned variation
echo $result->value; // "A" or "B"
```

The `inExperiment` flag can be false if the experiment defines any sort of targeting rules which the user does not pass.  In this case, the user is always assigned variation index `0` and the first variation value.

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

### Targeting

Experiments can target on these user attributes with the `targeting` field.  Here's an example:

```php
$result = $user->experiment(
    "my-targeted-experiment",
    ["A", "B"],
    [
      "targeting" => [
          "premium = true",
          "accountAge > 30"
      ]
    ]
])
```

If the user does not match the targeting rules, `$result->inExperiment` will be false and they will be assigned variation index `0`.

## Overriding Weights and Targeting

It's common practice to adjust experiment settings after a test is live.  For example, slowly ramping up traffic, stopping a test automatically if guardrail metrics go down, or rolling out a winning variation to 100% of users.

Instead of constantly changing your code, you can use client overrides.  For example, to roll out a winning variation to 100% of users:

```php
$client->overrides->set("experiment-key", [
    "status" => 'stopped',
    // Force variation index 1
    "force" => 1
]);
```

The full list of experiment properties you can override is:
*  status
*  force
*  weights
*  coverage
*  targeting
*  url

This data structure can be easily seralized and stored in a database or returned from an API.  There is a small helper function if you have all of your overrides in a single JSON object:

```php
$json = '{
  "experiment-key-1": {
    "status": "stopped"
  },
  "experiment-key-2": {
    "weights": [0.8, 0.2]
  }
}';

// Convert to associative array
$overrides = json_decode($json, true);

// Import into the client
$client->importOverrides($overrides);
```

### Tracking

It's likely you already have some event tracking on your site with the metrics you want to optimize (Google Analytics, Segment, Mixpanel, etc.).

For A/B tests, you just need to track one additional event - when someone views a variation.  

You can call `$client->getViewedExperiments()` at the end of a request to forward to your analytics tool of choice.

```php
$impressions = $client->getViewedExperiments();
foreach($impressions as $impression) {
  // Whatever you use for event tracking
  Segment::track([
    "userId" => $impression->userId,
    "event" => "Experiment Viewed",
    "properties" => [
      "experimentId" => $impression->experimentId,
      "variationId" => $impression->variationId
    ]
  ])
}
```

Each impression object has the following properties:
-  experimentId (the key of the experiment)
-  variationId (the array index of the assigned variation)
-  value (the value of the assigned variation)
-  experiment (the full experiment object)
-  userId
-  anonId
-  userAttributes

Often times you'll want to do the event tracking from the front-end with javascript.  To do this, simply add a block to your template (shown here in plain PHP, but similar idea for Twig, Blade, etc.).


```php
<script>
<?php foreach($client->getViewedExperiments() as $impression): ?>
  // tracking code goes here
<?php endforeach; ?>
</script>
```

Below are examples for a few popular front-end tracking libraries:

#### Google Analytics
```php
ga('send', 'event', 'experiment', 
  "<?= $impression->experimentId ?>", 
  "<?= $impression->variationId ?>", 
  {
    // Custom dimension for easier analysis
    'dimension1': "<?= 
      $impression->experimentId.':'.$impression->variationId 
    ?>"
  }
);
```

#### Segment
```php
analytics.track("Experiment Viewed", <?=json_encode([
  "experimentId" => $impression->experimentId,
  "variationId" => $impression->variationId 
])?>);
```

#### Mixpanel
```php
mixpanel.track("Experiment Viewed", <?=json_encode([
  'Experiment name' => $impression->experimentId,
  'Variant name' => $impression->variationId 
])?>);
```

### Analysis

For analysis, there are a few options:

*  Online A/B testing calculators
*  Built-in A/B test analysis in Mixpanel/Amplitude
*  Python or R libraries and a Jupyter Notebook
*  The [Growth Book App](https://github.io/growthbook/growthbook) (more info below)

### The Growth Book App

Managing experiments and analyzing results at scale can be complicated, which is why we built the open source **Growth Book App** - https://github.io/growthbook/growthbook.  It's completely optional, but definitely worth checking out.

- Query multiple data sources (Snowflake, Redshift, BigQuery, Mixpanel, Postgres, Athena, and Google Analytics)
- Bayesian statistics engine with support for binomial, count, duration, and revenue metrics
- Drill down into A/B test results (e.g. by browser, country, etc.)
- Lightweight idea board and prioritization framework
- Document everything! (upload screenshots, add markdown comments, and more)
- Automated email alerts when tests become significant

Integration is super easy:

1.  Create a Growth Book API key
2.  Periodically fetch the latest experiment overrides from the API and cache in Redis, Mongo, etc.
3.  At the start of your app, run `$client->importOverrides($listFromCache);`

Now you can start/stop tests, adjust coverage and variation weights, and apply a winning variation to 100% of traffic, all within the Growth Book App without deploying code changes to your site.