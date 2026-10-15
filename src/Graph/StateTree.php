<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;
use ReflectionEnum;
use RobertBoes\Interlock\Attributes\ParentState;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Support\PhpAttribute;

final readonly class StateTree
{
    /**
     * @param  class-string<BackedEnum>  $enum
     * @param  array<int|string, BackedEnum>  $parents  keyed by the nested state's value
     */
    private function __construct(
        public string $enum,
        private array $parents,
    ) {}

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public static function of(string $enum): self
    {
        $parents = [];

        foreach ((new ReflectionEnum($enum))->getCases() as $case) {
            $parent = PhpAttribute::of($case, ParentState::class)?->state;

            if ($parent === null) {
                continue;
            }

            if ($parent::class !== $enum) {
                throw InvalidDefinitionException::parentStateOfAnotherEnum($enum, $case->name, $parent);
            }

            $parents[$case->getBackingValue()] = $parent;
        }

        self::rejectStatesNestedWithinThemselves($enum, $parents);

        return new self($enum, $parents);
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @param  array<int|string, BackedEnum>  $parents
     */
    private static function rejectStatesNestedWithinThemselves(string $enum, array $parents): void
    {
        foreach ($enum::cases() as $state) {
            $ancestry = [];

            for ($current = $state; $current !== null; $current = $parents[$current->value] ?? null) {
                $cycleStart = array_search($current, $ancestry, true);

                if ($cycleStart !== false) {
                    throw InvalidDefinitionException::stateNestedWithinItself($current, [...array_slice($ancestry, $cycleStart), $current]);
                }

                $ancestry[] = $current;
            }
        }
    }

    public function isParent(BackedEnum $state): bool
    {
        return in_array($state, $this->parents, true);
    }

    public function parentOf(BackedEnum $state): ?BackedEnum
    {
        return $this->parents[$state->value] ?? null;
    }

    public function isWithin(BackedEnum $state, BackedEnum $ancestor): bool
    {
        for ($current = $state; $current !== null; $current = $this->parents[$current->value] ?? null) {
            if ($current === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<BackedEnum>
     */
    public function childrenOf(?BackedEnum $parent): array
    {
        return array_values(array_filter(
            $this->enum::cases(),
            fn(BackedEnum $case) => ($this->parents[$case->value] ?? null) === $parent,
        ));
    }

    /**
     * @return list<BackedEnum>
     */
    public function leaves(): array
    {
        return array_values(array_filter($this->enum::cases(), fn(BackedEnum $case) => ! $this->isParent($case)));
    }

    /**
     * @return list<BackedEnum>
     */
    public function leavesWithin(BackedEnum $state): array
    {
        return array_values(array_filter(
            $this->enum::cases(),
            fn(BackedEnum $case) => ! $this->isParent($case) && $this->isWithin($case, $state),
        ));
    }
}
