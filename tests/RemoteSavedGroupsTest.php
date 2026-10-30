<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Growthbook\Condition;
use Growthbook\Growthbook;
use Growthbook\InlineExperiment;
use Growthbook\RedisResolver;
use Growthbook\RemoteSavedGroups;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RemoteSavedGroupsTest extends TestCase
{
    private const SAVED_GROUPS = [
        'grp_vip' => ['type' => 'remote', 'attributeKey' => 'account_id'],
        'grp_vip:parent_id' => ['type' => 'remote', 'attributeKey' => 'parent_id', 'valuesFrom' => 'grp_vip'],
        'grp_list' => ['type' => 'list', 'attributeKey' => 'id', 'values' => ['u_1']],
    ];

    // GrowthBook guards "not in group" with $exists, as in the spec cases
    private const NOT_IN_VIP = [
        '$not' => ['$savedGroup' => ['id' => 'grp_vip']],
        '__gb_remoteGroupIds' => ['$exists' => true],
    ];

    /** @var array<int,array{0:string,1:string,2:array<mixed>}> */
    private $logs = [];

    protected function setUp(): void
    {
        $warned = new ReflectionProperty(Growthbook::class, 'warnedMissingRemoteGroupIds');
        if (PHP_VERSION_ID < 80100) {
            $warned->setAccessible(true);
        }
        $warned->setValue(null, false);
        $this->logs = [];
    }

    /**
     * @param array<string,mixed> $options
     */
    private function growthbook(array $options = []): Growthbook
    {
        $logs = &$this->logs;
        $logger = new class ($logs) extends AbstractLogger {
            /** @var array<int,array{0:string,1:string,2:array<mixed>}> */
            private $logs;

            /**
             * @param array<int,array{0:string,1:string,2:array<mixed>}> $logs
             */
            public function __construct(array &$logs)
            {
                $this->logs = &$logs;
            }

            /**
             * @param mixed $level
             * @param string|\Stringable $message
             * @param array<mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->logs[] = [(string) $level, (string) $message, $context];
            }
        };

        return new Growthbook($options + [
            'logger' => $logger,
            'savedGroups' => self::SAVED_GROUPS,
            'attributes' => ['account_id' => 'a1'],
            'features' => [
                'in_vip' => ['defaultValue' => false, 'rules' => [['condition' => ['$savedGroup' => ['id' => 'grp_vip']], 'force' => true]]],
                'not_in_vip' => ['defaultValue' => false, 'rules' => [['condition' => self::NOT_IN_VIP, 'force' => true]]],
            ],
        ]);
    }

    /**
     * @return string[]
     */
    private function warnings(): array
    {
        $out = [];
        foreach ($this->logs as [$level, $message, $context]) {
            if ($level === 'warning') {
                $out[] = $message . ' ' . json_encode($context);
            }
        }
        return $out;
    }

    public function testRemoteGroupIdsMatchTheGroupsTheyList(): void
    {
        $gb = $this->growthbook(['remoteGroupIds' => ['grp_vip']]);
        $this->assertSame(['grp_vip'], $gb->getRemoteGroupIds());
        $this->assertTrue($gb->isOn('in_vip'));
        $this->assertFalse($gb->isOn('not_in_vip'));
    }

    public function testUnresolvedIdsFailEveryRuleUsingARemoteGroup(): void
    {
        $gb = $this->growthbook();
        $this->assertNull($gb->getRemoteGroupIds());
        $this->assertFalse($gb->isOn('in_vip'));
        $this->assertFalse($gb->isOn('not_in_vip'));
    }

    public function testAnEmptyListIsInNoGroup(): void
    {
        $gb = $this->growthbook(['remoteGroupIds' => []]);
        $this->assertFalse($gb->isOn('in_vip'));
        $this->assertTrue($gb->isOn('not_in_vip'));
    }

    public function testIdsWinOverTheAttributeWithoutChangingTheAttributes(): void
    {
        $attributes = ['account_id' => 'a1', '__gb_remoteGroupIds' => ['grp_vip']];
        $gb = $this->growthbook(['attributes' => $attributes]);
        $this->assertTrue($gb->isOn('in_vip'));

        $gb->setRemoteGroupIds([]);
        $this->assertFalse($gb->isOn('in_vip'));
        $this->assertSame($attributes, $gb->getAttributes());

        $gb->setRemoteGroupIds(null);
        $this->assertTrue($gb->isOn('in_vip'));
    }

    public function testIdsKeepApplyingWhenTheAttributesChange(): void
    {
        $gb = $this->growthbook(['remoteGroupIds' => ['grp_vip']]);
        $gb->setAttributes(['account_id' => 'a2']);
        $this->assertTrue($gb->isOn('in_vip'));
    }

    public function testWithRemoteGroupIdsLeavesTheOriginalAsItWas(): void
    {
        $gb = $this->growthbook();
        $copy = $gb->withRemoteGroupIds(['grp_vip']);
        $this->assertTrue($copy->isOn('in_vip'));
        $this->assertNull($gb->getRemoteGroupIds());
        $this->assertFalse($gb->isOn('in_vip'));
    }

    public function testInGroupOperatorsOnARemoteGroupAreUndecided(): void
    {
        $attributes = ['account_id' => 'a1', '__gb_remoteGroupIds' => ['grp_vip']];
        $this->assertFalse(Condition::evalCondition($attributes, ['account_id' => ['$inGroup' => 'grp_vip']], self::SAVED_GROUPS));
        $this->assertFalse(Condition::evalCondition($attributes, ['account_id' => ['$notInGroup' => 'grp_vip']], self::SAVED_GROUPS));
    }

    public function testTheMissingIdsWarningIsLoggedOnceWithoutAttributeValues(): void
    {
        $gb = $this->growthbook(['attributes' => ['account_id' => 'secret-account']]);
        $gb->isOn('in_vip');
        $gb->isOn('in_vip');
        $this->growthbook()->runInlineExperiment(InlineExperiment::create('e', [0, 1]));

        $warnings = $this->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('no remote group ids', $warnings[0]);
        $this->assertStringNotContainsString('secret-account', $warnings[0]);
    }

    public function testTheMissingIdsWarningIsNotLoggedWhenTheIdsAreKnown(): void
    {
        $this->growthbook(['remoteGroupIds' => []])->isOn('in_vip');
        $this->growthbook(['attributes' => ['__gb_remoteGroupIds' => []]])->isOn('in_vip');
        $this->growthbook(['savedGroups' => ['grp_list' => self::SAVED_GROUPS['grp_list']]])->isOn('in_vip');
        $this->assertSame([], $this->warnings());
    }

    public function testAddRemoteSavedGroupsPassesOnlyTheAttributesRemoteGroupsUse(): void
    {
        $calls = [];
        $gb = $this->growthbook(['attributes' => ['account_id' => 'a1', 'email' => 'user@example.test', 'id' => 'u_1']]);
        $result = $gb->addRemoteSavedGroups(function (array $attributes, array $groups) use (&$calls): array {
            $calls[] = [$attributes, $groups];
            return ['grp_vip'];
        });

        $this->assertSame($gb, $result);
        $this->assertSame(['grp_vip'], $gb->getRemoteGroupIds());
        $this->assertTrue($gb->isOn('in_vip'));
        $this->assertSame([[
            ['account_id' => 'a1'],
            [
                ['id' => 'grp_vip', 'attributeKey' => 'account_id'],
                ['id' => 'grp_vip:parent_id', 'attributeKey' => 'parent_id', 'valuesFrom' => 'grp_vip'],
            ],
        ]], $calls);
    }

    public function testAddRemoteSavedGroupsReadsADotSeparatedAttributeKeyAsAPath(): void
    {
        $received = null;
        $gb = $this->growthbook([
            'attributes' => ['company' => ['id' => 'c1']],
            'savedGroups' => ['grp_co' => ['type' => 'remote', 'attributeKey' => 'company.id']],
        ]);
        $gb->addRemoteSavedGroups(function (array $attributes) use (&$received): array {
            $received = $attributes;
            return [];
        });
        $this->assertSame(['company.id' => 'c1'], $received);
    }

    public function testAddRemoteSavedGroupsSkipsTheResolverWithoutRemoteGroups(): void
    {
        $gb = $this->growthbook(['savedGroups' => ['grp_list' => self::SAVED_GROUPS['grp_list']]]);
        $gb->addRemoteSavedGroups(function (): array {
            throw new \LogicException('not called');
        });
        $this->assertNull($gb->getRemoteGroupIds());
    }

    public function testAddRemoteSavedGroupsLeavesTheIdsWhenTheResolverThrows(): void
    {
        $gb = $this->growthbook(['remoteGroupIds' => ['grp_old'], 'attributes' => ['account_id' => 'secret-account']]);
        $gb->addRemoteSavedGroups(function (): array {
            throw new \RuntimeException('gb:member:account_id:secret-account');
        });

        $this->assertSame(['grp_old'], $gb->getRemoteGroupIds());
        $warnings = $this->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('RuntimeException', $warnings[0]);
        $this->assertStringNotContainsString('secret-account', $warnings[0]);
    }

    public function testAddRemoteSavedGroupsLeavesTheIdsWhenTheResolverReturnsNoArray(): void
    {
        $gb = $this->growthbook();
        // @phpstan-ignore-next-line A resolver returning the wrong type
        $gb->addRemoteSavedGroups(function () {
            return 'grp_vip';
        });
        $this->assertNull($gb->getRemoteGroupIds());
        $this->assertStringContainsString('UnexpectedValueException', $this->warnings()[0]);
    }

    public function testAddRemoteSavedGroupsLooksUpAgainWhenTheSavedGroupsChange(): void
    {
        $gb = $this->growthbook();
        $seen = [];
        $gb->addRemoteSavedGroups(function (array $attributes, array $groups) use ($gb, &$seen): array {
            $seen[] = array_column($groups, 'id');
            if (count($seen) === 1) {
                $gb->setSavedGroups(self::SAVED_GROUPS + ['grp_new' => ['type' => 'remote', 'attributeKey' => 'account_id']]);
                return ['grp_vip'];
            }
            return ['grp_vip', 'grp_new'];
        });

        $this->assertSame([['grp_vip', 'grp_vip:parent_id'], ['grp_vip', 'grp_vip:parent_id', 'grp_new']], $seen);
        $this->assertSame(['grp_vip', 'grp_new'], $gb->getRemoteGroupIds());
    }

    public function testAddRemoteSavedGroupsLeavesTheIdsWhenTheNewSavedGroupsHaveNoRemoteGroups(): void
    {
        $gb = $this->growthbook();
        $gb->addRemoteSavedGroups(function () use ($gb): array {
            $gb->setSavedGroups([]);
            return ['grp_vip'];
        });
        $this->assertNull($gb->getRemoteGroupIds());
    }

    public function testWithRemoteSavedGroupsLeavesTheOriginalAsItWas(): void
    {
        $gb = $this->growthbook();
        $copy = $gb->withRemoteSavedGroups(function (): array {
            return ['grp_vip'];
        });
        $this->assertSame(['grp_vip'], $copy->getRemoteGroupIds());
        $this->assertNull($gb->getRemoteGroupIds());
    }

    public function testPruningLeavesARemoteGroupUndecidedWhileTheIdsAreUnknown(): void
    {
        $condition = ['$savedGroup' => ['id' => 'grp_vip']];
        $this->assertSame($condition, Condition::pruneCondition(['account_id' => 'a1'], $condition, self::SAVED_GROUPS));
        $this->assertTrue(Condition::pruneCondition(['__gb_remoteGroupIds' => ['grp_vip']], $condition, self::SAVED_GROUPS));
        $this->assertFalse(Condition::pruneCondition(['__gb_remoteGroupIds' => []], $condition, self::SAVED_GROUPS));
        $this->assertFalse(Condition::pruneCondition([], ['$savedGroup' => ['id' => 'grp_vip', 'attributeKey' => 'parent_id']], self::SAVED_GROUPS));
    }

    public function testAReducedPayloadKeepsTheRemoteGroupsItUses(): void
    {
        $payload = [
            'features' => ['in_vip' => ['defaultValue' => false, 'rules' => [['condition' => ['$savedGroup' => ['id' => 'grp_vip']], 'force' => true]]]],
            'savedGroups' => self::SAVED_GROUPS,
        ];
        $reduced = json_decode((string) json_encode(Growthbook::reducePayload($payload, ['attributes' => ['account_id' => 'a1']])), true);
        $this->assertSame(['grp_vip' => self::SAVED_GROUPS['grp_vip']], $reduced['savedGroups']);
        $this->assertSame(['$savedGroup' => ['id' => 'grp_vip']], $reduced['features']['in_vip']['rules'][0]['condition']);
    }

    /**
     * @param array<string,array<mixed>> $sets
     */
    private function redis(array $sets = []): FakeRedis
    {
        return new FakeRedis($sets);
    }

    /**
     * @return array<int,array{id:string,attributeKey:string,valuesFrom?:string}>
     */
    private function groups(): array
    {
        return RemoteSavedGroups::fromSavedGroups(self::SAVED_GROUPS);
    }

    public function testRedisResolverLooksUpEachAttributeValueInItsGroup(): void
    {
        $redis = $this->redis(['gb:member:account_id:a1' => ['grp_vip', 'grp_other']]);
        $this->assertSame(['grp_vip'], (new RedisResolver($redis))(['account_id' => 'a1'], $this->groups()));
        $this->assertSame(['gb:member:account_id:a1'], $redis->keys);
    }

    public function testRedisResolverChecksADerivedGroupInItsSourceGroupsSets(): void
    {
        $redis = $this->redis(['gb:member:account_id:p1' => ['grp_vip']]);
        $ids = (new RedisResolver($redis))(['account_id' => 'a1', 'parent_id' => 'p1'], $this->groups());
        $this->assertSame(['grp_vip:parent_id'], $ids);
    }

    public function testRedisResolverSkipsADerivedGroupWhoseSourceIsMissing(): void
    {
        $redis = $this->redis(['gb:member:parent_id:p1' => ['grp_vip:parent_id']]);
        $this->assertSame([], (new RedisResolver($redis))(['parent_id' => 'p1'], [$this->groups()[1]]));
        $this->assertSame([], $redis->keys);
    }

    public function testRedisResolverKeepsKeysCollidingOnAColonApart(): void
    {
        $groups = RemoteSavedGroups::fromSavedGroups([
            'grp_a' => ['type' => 'remote', 'attributeKey' => 'a:b'],
            'grp_ab' => ['type' => 'remote', 'attributeKey' => 'a'],
        ]);
        // `a:b` + `c` and `a` + `b:c` both read gb:member:a:b:c
        $redis = $this->redis(['gb:member:a:b:c' => ['grp_a']]);
        $this->assertSame(['grp_a'], (new RedisResolver($redis))(['a:b' => 'c', 'a' => 'b:c'], $groups));
        $this->assertSame(['gb:member:a:b:c'], $redis->keys);
    }

    public function testRedisResolverLooksUpEachItemOfAListOncePerKey(): void
    {
        $redis = $this->redis(['gb:member:account_id:a2' => ['grp_vip']]);
        $this->assertSame(['grp_vip'], (new RedisResolver($redis))(['account_id' => ['a1', 'a2', 'a1']], [$this->groups()[0]]));
        $this->assertSame(['gb:member:account_id:a1', 'gb:member:account_id:a2'], $redis->keys);
    }

    public function testRedisResolverFormatsNumbersLikeJsAndSkipsOtherValues(): void
    {
        $redis = $this->redis();
        $values = [1, 2.0, 1.5, 0.00001, 1e21, -0.0, NAN, INF, true, null, ['a' => 1]];
        (new RedisResolver($redis))(['account_id' => $values], [$this->groups()[0]]);
        $this->assertSame([
            'gb:member:account_id:1',
            'gb:member:account_id:2',
            'gb:member:account_id:1.5',
            'gb:member:account_id:0.00001',
            'gb:member:account_id:1e+21',
            'gb:member:account_id:0',
        ], $redis->keys);
    }

    public function testRedisResolverFailsWhenSmembersFails(): void
    {
        $redis = new class () {
            /** @return false */
            public function sMembers(string $key)
            {
                return false;
            }
        };
        $this->expectException(\UnexpectedValueException::class);
        (new RedisResolver($redis))(['account_id' => 'a1'], [$this->groups()[0]]);
    }

    public function testRedisResolverWorksThroughAddRemoteSavedGroups(): void
    {
        $gb = $this->growthbook()->addRemoteSavedGroups(new RedisResolver($this->redis(['gb:member:account_id:a1' => ['grp_vip']])));
        $this->assertSame(['grp_vip'], $gb->getRemoteGroupIds());
        $this->assertTrue($gb->isOn('in_vip'));
    }
}

/**
 * Records each SMEMBERS key
 */
final class FakeRedis
{
    /** @var string[] */
    public $keys = [];
    /** @var array<string,array<mixed>> */
    private $sets;

    /**
     * @param array<string,array<mixed>> $sets
     */
    public function __construct(array $sets)
    {
        $this->sets = $sets;
    }

    /**
     * @return array<mixed>
     */
    public function sMembers(string $key): array
    {
        $this->keys[] = $key;
        return $this->sets[$key] ?? [];
    }
}
