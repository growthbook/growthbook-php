<p align="center"><img src="https://www.growthbook.io/logos/growthbook-logo@2x.png" width="400px" /></p>

# Growth Book - PHP

Powerful Feature flagging and A/B testing for PHP.

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
// Get current feature flags from GrowthBook API
// TODO: persist this in a database or cache in production
const FEATURES_ENDPOINT = 'https://cdn.growthbook.io/api/features/key_prod_abc123';
$features = json_decode(file_get_contents(FEATURES_ENDPOINT), true)["features"];

// Create a GrowthBook instance
$growthbook = new Growthbook\Growthbook([
  'features' => $features,
  // Targeting attributes for the user
  'attributes' => [
    'id' => $userId,
    'someCustomAttribute' => true
  ],
]);

// Evaluate a feature flag
if($growthbook->feature("my-feature")->on) {
  echo "My feature is enabled!";
}

// Remote configuration with fallback
$color = $growthbook->feature("button-color")->value ?? "blue";
echo "<button style='color:${color}'>Click Me!</button>";
```

Some of the feature flags you evaluate might be running an A/B test behind the scenes which you'll want to track in your analytics system.

At the end of the request, you can loop through all experiments and track them however you want to:

```php
$impressions = $growthbook->getViewedExperiments();
foreach($impressions as $impression) {
  // Whatever you use for event tracking
  Segment::track([
    "userId" => $userId,
    "event" => "Experiment Viewed",
    "properties" => [
      "experimentId" => $impression->experiment->key,
      "variationId" => $impression->result->variationId
    ]
  ])
}
```

## The Growthbook Class

The `Growthbook` constructor takes an associative array with a number of optional properties.

### Features

Defines all of the available features plus rules for how to assign values to users.

```php
new Growthbook\Growthbook([
  'features'=> [
    'feature-1': [...],
    'feature-2': [...]
  ]
])
```

Feature definitions are stored in a JSON format. You can fetch them directly from the GrowthBook API:

```php
const FEATURES_ENDPOINT = 'https://cdn.growthbook.io/api/features/key_prod_abc123';
$features = json_decode(
  file_get_contents(FEATURES_ENDPOINT),
  true
)["features"];
```

Or, you can use a copy stored in your database or cache server instead:

```php
// From database/cache
$jsonString = '{"feature-1": {...}, "feature-2": {...}}';
$features = json_decode($jsonString, true);
```

We recomend the cache approach for production.

### Attributes

You can specify attributes about the current user and request. These are used for two things:

1.  Feature targeting (e.g. paid users get one value, free users get another)
2.  Assigning persistent variations in A/B tests (e.g. user id "123" always gets variation B)

Attributes can be any JSON data type - boolean, integer, float, string, array, or object.

```php
new Growthbook\Growthbook([
  'attributes' => [
    'id' => "123",
    'loggedIn' => true,
    'deviceId' => "abc123def456",
    'company' => "acme",
    'paid' => false,
    'url' => "/pricing",
    'browser' => "chrome",
    'mobile' => false,
    'country' => "US",
  ],
]);
```

You can also set or update attributes asynchronously at any time with the `setAttributes` method. This will completely overwrite the attributes object with whatever you pass in. If you want to merge attributes instead, you can get the existing ones with `getAttributes`:

```ts
// Only update the url attribute
$growthbook->setAttributes(array_merge(
  $growthbook->getAttributes(),
  [
    'url' => '/checkout'
  ]
));
```

### Tracking Callback

Any time an experiment is run to determine the value of a feature, you want to track that event in your analytics system.

You can either track them via a callback function:

```php
new Growthbook([
  'trackingCallback' => function ($experiment, $result) {  
    // Segment.io example
    Segment::track([
      "userId" => $userId,
      "event" => "Experiment Viewed",
      "properties" => [
        "experimentId" => $experiment->key,
        "variationId" => $result->variationId
      ]
    ])
  }
])
```

Or track them at the end of the request by looping through an array:

```php
$impressions = $growthbook->getViewedExperiments();
foreach($impressions as $impression) {
  // Segment.io example
  Segment::track([
    "userId" => $userId,
    "event" => "Experiment Viewed",
    "properties" => [
      "experimentId" => $impression->experiment->key,
      "variationId" => $impression->result->variationId
    ]
  ])
}
```

Or, you can pass the impressions onto your front-end and fire analytics events from there. To do this, simply add a block to your template (shown here in plain PHP, but similar idea for Twig, Blade, etc.).

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
  "<?= $impression->experiment->key ?>", 
  "<?= $impression->result->variationId ?>", 
  {
    // Custom dimension for easier analysis
    'dimension1': "<?= 
      $impression->experiment->key.':'.$impression->result->variationId 
    ?>"
  }
);
```

#### Segment
```php
analytics.track("Experiment Viewed", <?=json_encode([
  "experimentId" => $impression->experiment->key,
  "variationId" => $impression->result->variationId 
])?>);
```

#### Mixpanel
```php
mixpanel.track("Experiment Viewed", <?=json_encode([
  'Experiment name' => $impression->experiment->key,
  'Variant name' => $impression->result->variationId 
])?>);
```

## Using Features

The main method, `$growthbook->feature("key")` takes a feature key and returns a `FeatureResult` object with a few properties:

- **value** - The JSON-decoded value of the feature (or `null` if not defined)
- **on** and **off** - The JSON-decoded value cast to booleans (to make your code easier to read)
- **source** - Why the value was assigned to the user. One of `unknownFeature`, `defaultValue`, `force`, or `experiment`
- **experiment** - Information about the experiment (if any) which was used to assign the value to the user

Here's an example that uses all of them:

```php
$result = $growthbook->feature("my-feature");

// The value (might be null, string, boolean, int, float, array, or object)
print_r($result->value);

if ($result->on) {
  // Feature value is truthy
}
if ($result->off) {
  // Feature value is falsy
}

// If the feature value was assigned as part of an experiment
if ($result->source === "experiment") {
  // Get all the possible variations that could have been assigned
  print_r($result->experiment->variations);
}
```

## Inline Experiments

Instead of declaring all features up-front in the constructor and referencing them by ids in your code, you can also just run an experiment directly. This is done with the `$growthbook->run` method:

```js
$exp = new Growthbook\Experiment("my-experiment", [
  "red",
  "blue",
  "green"
])

echo $growthbook->run($exp)->value; // Either "red", "blue", or "green"
```

As you can see, there are 2 required parameters for experiments, a string key, and an array of variations.  Variations can be any data type, not just strings.

There are a number of additional settings to control the experiment behavior. The methods are all chainable. Here's an example that shows all of the possible settings:

```php
$exp = new Growthbook\Experiment("my-experiment", ["red","blue"])
  // Run a 40/60 experiment instead of the default 50/50
  ->withWeights([0.4, 0.6])
  // Only include 20% of users in the experiment
  ->withCoverage(0.2)
  // Targeting conditions using a MongoDB-like syntax
  ->withCondition([
    'country' => 'US',
    'browser' => [
      '$in' => ['chrome', 'firefox']
    ]
  ])
  // Use an alternate attribute for assigning variations (default is 'id')
  ->withHashAttribute("sessionId")
  // Namespaces are used to run mutually exclusive experiments
  // Another experiment in the "pricing" namespace with a non-overlapping range
  //   will be mutually exclusive (e.g. [0.5, 1])
  ->withNamespace("pricing", 0, 0.5);
```

### Inline Experiment Return Value

A call to `$growthbook->run($experiment)` returns a `ExperimentResult` object with a few useful properties:

```php
$result = $growthbook->run($experiment);

// If user is part of the experiment
echo($result->inExperiment); // true or false

// The index of the assigned variation
echo($result->variationId); // e.g. 0 or 1

// The value of the assigned variation
echo($result->value); // e.g. "A" or "B"

// The user attribute used to assign a variation
echo($result->hashAttribute); // "id"

// The value of that attribute
echo($result->hashValue); // e.g. "123"
```

The `inExperiment` flag is only set to true if the user was randomly assigned a variation. If the user failed any targeting rules or was forced into a specific variation, this flag will be false.
