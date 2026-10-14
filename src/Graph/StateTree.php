<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;
use ReflectionEnum;
use RobertBoes\Interlock\Attributes\ParentState;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;

/**
 * How the states of one enum nest, read from the #[ParentState] attributes on its cases (ADR 0006).
 */
final readonly class StateTree
{
    /**
     * @param  class-string<BackedEnum>  $enum
     * @param  array<int|string, BackedEnum>  $parents  each nested state's parent state, keyed by the nested state's value
     */
    private function __construct(
        private string $enum,
        private array $parents,
    ) {}

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public static function of(string $enum): self
    {
        $parents = [];

        foreach ((new ReflectionEnum($enum))->getCases() as $case) {
            $attribute = $case->getAttributes(ParentState::class)[0] ?? null;

            if ($attribute === null) {
                continue;
            }

            $parent = $attribute->newInstance()->state;

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

    /**
     * Whether the state is the given state, or nested within it at any depth.
     */
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
     * Every state a model can be in: each state that is not a parent state.
     *
     * @return list<BackedEnum>
     */
    public function leaves(): array
    {
        return array_values(array_filter($this->enum::cases(), fn(BackedEnum $case) => ! $this->isParent($case)));
    }

    /**
     * The leaf states the given state stands for: itself when it is a leaf, otherwise every leaf nested within it.
     *
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
