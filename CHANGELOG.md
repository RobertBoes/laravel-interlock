# Changelog

## 0.1.0 (2026-10-23)


### ⚠ BREAKING CHANGES

* authorise() is now authorize(), #[Authorise] is now #[Authorize] and UnauthorisedTransitionException is now UnauthorizedTransitionException.
* **scopes:** use InFromStateOf instead of CanTransition, and whereInFromStateOf() instead of whereCanTransition().
* **scopes:** pass `stateAttribute:` instead of `attribute:` to InState, NotInState and #[UseStateMachine].

### Features

* **boost:** ship Laravel Boost guidelines ([aacf711](https://github.com/RobertBoes/laravel-interlock/commit/aacf711275b1f674908d47bab20d0239275880c0))
* **bypass:** allow direct writes inside a reasoned bypass, from factories and in opted-in tests ([5e42895](https://github.com/RobertBoes/laravel-interlock/commit/5e42895824355f425b9daeaa9127858fd9e550f4))
* **discovery:** discover interlocked models and cache the registry ([c8ca0d4](https://github.com/RobertBoes/laravel-interlock/commit/c8ca0d499a6d19edae2e5e8bf210c40477a8ab44))
* **enforcement:** guard attributes that only named transitions may write, and reset state on replicate ([e9ff33f](https://github.com/RobertBoes/laravel-interlock/commit/e9ff33fd070d48ecd5843396b2e5e9a769f0f6d2))
* **enforcement:** reject direct writes to state attributes and enforce initial states ([4852b26](https://github.com/RobertBoes/laravel-interlock/commit/4852b262229118ffe24d9c975cf03169265a7e11))
* **enforcement:** reject mass writes to state attributes through the Eloquent builder ([c870f5c](https://github.com/RobertBoes/laravel-interlock/commit/c870f5c147f76a478780c1e069e50948ee4f9a79))
* **export:** export state machines as Mermaid diagrams with interlock:signalbox ([e78dfbb](https://github.com/RobertBoes/laravel-interlock/commit/e78dfbb9335736003965784ad95ba6a5d260ccf2))
* **export:** export state machines as XState configs in JSON and TypeScript ([6d826c0](https://github.com/RobertBoes/laravel-interlock/commit/6d826c06a8a928257a7673fbed49e0db9b90d34e))
* **export:** export the machines for Stately Sketch with --viz ([738a1e1](https://github.com/RobertBoes/laravel-interlock/commit/738a1e1a1f68753a40103d9b30e347b88ffefc89))
* **history:** name the history recorder in the config ([5c4caff](https://github.com/RobertBoes/laravel-interlock/commit/5c4caff40958d5f8005c872b280dc9b3463c4930))
* **history:** record transitions and bypassed writes in opt-in history ([d46cc86](https://github.com/RobertBoes/laravel-interlock/commit/d46cc86a1c25632ff22f77163ed12044938af9bc))
* **history:** share the history table between models with integer, UUID and ULID keys ([f142dc0](https://github.com/RobertBoes/laravel-interlock/commit/f142dc0109385d2d6799bda7087c6b2e56c051bd))
* **machines:** allow several state machines per model with unique transition names ([7e6e402](https://github.com/RobertBoes/laravel-interlock/commit/7e6e402669433cd6da044dce698a008b818f8021))
* **scopes:** query models by state with tappable scopes and opt-in scope methods ([157b1f9](https://github.com/RobertBoes/laravel-interlock/commit/157b1f98300209062fa0f2d316f94ae74a4349b6))
* **soundness:** declare final states and check every machine for soundness ([971c60c](https://github.com/RobertBoes/laravel-interlock/commit/971c60c35064825458a7bdc8d6ccf43e943a0296))
* **states:** nest states under parent states ([cb3bb39](https://github.com/RobertBoes/laravel-interlock/commit/cb3bb3991eb3eae51702a64f9d3a2b29bfffc15f))
* **states:** record when a state was entered and query time in state ([9a65673](https://github.com/RobertBoes/laravel-interlock/commit/9a65673e400f3cd29811fdae9c1b9c95ab430aff))
* **testing:** assert on transitions and fake Interlock in tests ([ea9f499](https://github.com/RobertBoes/laravel-interlock/commit/ea9f4998a991c30f15c41eb6fbf17a7b93291f94))
* **transitions:** apply fluent transitions to a model's state attribute ([50582aa](https://github.com/RobertBoes/laravel-interlock/commit/50582aae66f2cf43b89672dece01b36cf8bf93d1))
* **transitions:** apply system-only transitions inside a system scope ([ecae3be](https://github.com/RobertBoes/laravel-interlock/commit/ecae3bedbcfc0121dd891d184a5019296a121ab1))
* **transitions:** apply transitions by signal ([4d5359b](https://github.com/RobertBoes/laravel-interlock/commit/4d5359bdffb9b10f42d05e18afda57baa14779e0))
* **transitions:** apply transitions with compare-and-swap and throw stale state ([03906a0](https://github.com/RobertBoes/laravel-interlock/commit/03906a0e371c26df132fe1add6c69c8c8569b92c))
* **transitions:** check guards and authorise the actor before a transition applies ([51c1509](https://github.com/RobertBoes/laravel-interlock/commit/51c1509aa3a27f743b106a527991c23878ced933))
* **transitions:** declare transitions as classes with apply and after-commit steps ([e41b30c](https://github.com/RobertBoes/laravel-interlock/commit/e41b30cc79a2b19d4a0982ce1a17ba84973d2a5a))
* **transitions:** list the transitions an actor can apply to a model right now ([63de76a](https://github.com/RobertBoes/laravel-interlock/commit/63de76a6ebe0941e1927185446e6fcfa88ff52ab))
* **transitions:** say which state attribute an available transition belongs to ([e9cae42](https://github.com/RobertBoes/laravel-interlock/commit/e9cae428a0fa6ae2ba9dcd20583e70c5b475db09))
* **transitions:** stage transitions for the next save to write in one update ([25be2fc](https://github.com/RobertBoes/laravel-interlock/commit/25be2fce3174df50839d8b39f5f98fa431605cb1))
* **triggers:** accept any callable as a trigger condition ([a65bc0e](https://github.com/RobertBoes/laravel-interlock/commit/a65bc0e35018020545e7a0978efaa37afa72d066))
* **triggers:** apply transitions by themselves once their trigger condition holds ([096d6d6](https://github.com/RobertBoes/laravel-interlock/commit/096d6d6c21d5969be56277cf14ec5fc0ed6d3c57))
* **triggers:** apply triggered transitions without checking authorisation ([536a91f](https://github.com/RobertBoes/laravel-interlock/commit/536a91ff6d56453967e943f322ad954e1847e168))
* **triggers:** cap trigger runs and let a busy trigger fan out into chunk jobs ([c8434fe](https://github.com/RobertBoes/laravel-interlock/commit/c8434fe6aae97c9107a2fddf419ce067101ea319))
* **triggers:** let apps disable or reschedule the trigger command ([76cbc5b](https://github.com/RobertBoes/laravel-interlock/commit/76cbc5b7d2c3b6178dda3a66a087ef1c8a7e697e))
* **triggers:** report trigger status and fail on overdue triggers ([ad6131b](https://github.com/RobertBoes/laravel-interlock/commit/ad6131bf9f5fa43d7d4e13a64c17ee601f0349ee))
* **triggers:** route trigger jobs to a configured connection and queue ([462b0ec](https://github.com/RobertBoes/laravel-interlock/commit/462b0ec35c6c28cda16745d87ddbfb7a7beb1d8d))


### Bug Fixes

* **bypass:** drop the bypassed writes of a save that fails ([a32cad1](https://github.com/RobertBoes/laravel-interlock/commit/a32cad10c65fa40e3433be0f9b424dfb5f4eace8))
* **definitions:** say how to fix an initial or final state of another enum ([f2ff45e](https://github.com/RobertBoes/laravel-interlock/commit/f2ff45e32ca89919b078a6f9ab98b327d440168b))
* **deps:** declare the illuminate packages the code imports ([a60ffe5](https://github.com/RobertBoes/laravel-interlock/commit/a60ffe5518505c315cf495d96ca2f0e1af812958))
* **discovery:** reject a model that declares a state machine without using HasStateMachines ([7cd60b8](https://github.com/RobertBoes/laravel-interlock/commit/7cd60b8ffc4d448e878d9e8bd774068d44281c68))
* **discovery:** say how to fix a registered model without a state machine ([ba9e1c9](https://github.com/RobertBoes/laravel-interlock/commit/ba9e1c94ba11df91fd700f60dfbcce054b2b07cc))
* **enforcement:** check the custom builder when it is built, not on instantiation ([18244d9](https://github.com/RobertBoes/laravel-interlock/commit/18244d912ead7e87302d67e2f47fae18c6682f62))
* **enforcement:** dispatch bypass events once the write commits ([49c66ec](https://github.com/RobertBoes/laravel-interlock/commit/49c66ec53385c45b9a872f809f5634b9e76375c5))
* **enforcement:** keep the save-query flag off the builder's public methods ([277127c](https://github.com/RobertBoes/laravel-interlock/commit/277127c4ed991e6e2798164c3f6f5a7283e7887e))
* **enforcement:** reject direct writes through updateOrInsert, updateFrom and the insertUsing variants ([f8494e7](https://github.com/RobertBoes/laravel-interlock/commit/f8494e77b000734a5647d601e9b240ea2b45ef94))
* **enforcement:** stop factories bypassing enforcement in production ([3806d4b](https://github.com/RobertBoes/laravel-interlock/commit/3806d4bf84e450592af8e4cd1271c0b1dbc4c03e))
* **events:** serialise models by key for queued listeners ([10bf3b5](https://github.com/RobertBoes/laravel-interlock/commit/10bf3b54c911f0f8ba14dac58d4b785b615ff927))
* **export:** refuse to export every machine as XState when two machines share a class name ([d983fa9](https://github.com/RobertBoes/laravel-interlock/commit/d983fa948ee1bf03fb3109167c330d10a773e67a))
* **history:** dispatch an event and record history for bypassed writes to guarded attributes ([0b09c16](https://github.com/RobertBoes/laravel-interlock/commit/0b09c161b2f05368a10f5235102776139ccf840e))
* **history:** record bypassed writes in the same transaction ([aa050e9](https://github.com/RobertBoes/laravel-interlock/commit/aa050e9854c8227ca59bc4cff07f68016c493f51))
* **history:** say how to fix a missing history table ([25a2a7a](https://github.com/RobertBoes/laravel-interlock/commit/25a2a7ab579a7931aa8e17b592b05e2645a0ed04))
* **http:** answer a transition from the wrong state with 409 ([6446f06](https://github.com/RobertBoes/laravel-interlock/commit/6446f06742a7d9e2424a9833676d3db0b7012e8f))
* **http:** keep internal details out of the stale-state response ([bf39f31](https://github.com/RobertBoes/laravel-interlock/commit/bf39f3162dbff924b76b6fe3321e3e83c1b117eb))
* **scopes:** accept relations so state scopes work as eager-load constraints ([218856b](https://github.com/RobertBoes/laravel-interlock/commit/218856bb1dffc443ed22ce79dab2916c023789de))
* **transitions:** build a transition class by name only when its constructor takes no input ([297ffd0](https://github.com/RobertBoes/laravel-interlock/commit/297ffd01d762e3de18b691184f1fbdb2f1f514dd))
* **transitions:** let a save nested in a transition write without a second compare-and-swap ([eefe383](https://github.com/RobertBoes/laravel-interlock/commit/eefe383886806579e0427863a282287a72d6dea7))
* **transitions:** point at job middleware when a system-only transition is refused ([ae956f0](https://github.com/RobertBoes/laravel-interlock/commit/ae956f040947c20532d4d496e9d032bf5322a522))
* **transitions:** put the original attributes back when a transition fails after its write ([a37196a](https://github.com/RobertBoes/laravel-interlock/commit/a37196af201ef2b896c941168f4d2eb93ec237a9))
* **transitions:** run every after-commit step before rethrowing ([f25aea2](https://github.com/RobertBoes/laravel-interlock/commit/f25aea2e6c62bd952e4e24a0749a435fc7827a1f))
* **transitions:** throw stale state when the update matches no row ([4728ba3](https://github.com/RobertBoes/laravel-interlock/commit/4728ba3245c7b4fa20098a8d5129f00ba66d3bf5))
* **transitions:** throw when a listener cancels the save of a transition ([b06fc80](https://github.com/RobertBoes/laravel-interlock/commit/b06fc80c42076becfd098350aa807fe5e9f30851))
* **triggers:** fail the run when the database connection is lost ([d71aba8](https://github.com/RobertBoes/laravel-interlock/commit/d71aba8e98da00382e5a64bf0a2fe8050ecc29b1))
* **triggers:** keep pending chunk markers for at least five minutes ([dc3ec83](https://github.com/RobertBoes/laravel-interlock/commit/dc3ec83984ca4fa0cf502c57ff66567146da9091))
* **triggers:** keep the trigger entry point off the model's public methods ([091fdbd](https://github.com/RobertBoes/laravel-interlock/commit/091fdbdfd7de336a08b99865fab0f834d90c01ef))
* **triggers:** read trigger run times back from Redis ([dca04eb](https://github.com/RobertBoes/laravel-interlock/commit/dca04ebd426dfccd4c90b4f95e69884fa8d3d2d2))
* **triggers:** reject a trigger on a transition class that takes input when the graph is compiled ([5d381fd](https://github.com/RobertBoes/laravel-interlock/commit/5d381fde9bf32eb73e2436a118a842030ba2e40d))
* **triggers:** release the lock and carry on when a trigger's dispatch fails ([12149e8](https://github.com/RobertBoes/laravel-interlock/commit/12149e8749e1310002c9837aea4263fd9b73fdb0))
* **triggers:** report a trigger that authorisation denies ([c35fd2a](https://github.com/RobertBoes/laravel-interlock/commit/c35fd2a5b47cd20a9f49c9f4a91ceb5b12e7b3a2))
* **triggers:** save a run's cursor after each chunk ([09e71c5](https://github.com/RobertBoes/laravel-interlock/commit/09e71c5130f75bfa65c6de36276980b1cd8b05f4))
* **triggers:** skip a queued trigger job whose transition was removed ([9796efa](https://github.com/RobertBoes/laravel-interlock/commit/9796efabfd8d32384fb958f6fb7fa6ea2e45b1e1))
* **triggers:** stop a run before the job times out ([e4bf38b](https://github.com/RobertBoes/laravel-interlock/commit/e4bf38ba1f7c10653a1892d0f45295dd84a1ab97))
* **triggers:** stop healthy triggers with steady traffic reading as overdue ([95dab44](https://github.com/RobertBoes/laravel-interlock/commit/95dab44f9cbe03eddb4ae3c74b14d1b72858b49f))
* **triggers:** track a fanned-out run's chunk jobs per run and per chunk ([fbecd5c](https://github.com/RobertBoes/laravel-interlock/commit/fbecd5c2fe37b05e4a86d3a7a3ebdd3960e7e610))
* **triggers:** treat a timeout of 0 as none and reject settings below their minimum ([fd38137](https://github.com/RobertBoes/laravel-interlock/commit/fd38137b82b061ef7f2aceef4928fce442693a55))


### Miscellaneous Chores

* add package skeleton ([55f9377](https://github.com/RobertBoes/laravel-interlock/commit/55f9377e070fea5edb57daee46936469cc05f148))
* add the MIT license file ([09f9eae](https://github.com/RobertBoes/laravel-interlock/commit/09f9eae5ac98af6a42c5fe655c5bf349ba2ae7a3))
* keep .claude out of git and the package archive ([d69a23c](https://github.com/RobertBoes/laravel-interlock/commit/d69a23cd78b08e0308d866eb0e2cda72fbc1528d))
* **pint:** switch to the PER preset ([07700f9](https://github.com/RobertBoes/laravel-interlock/commit/07700f9d42fb8b1d58e0b7f15a19794828cb6eb7))


### Code Refactoring

* **scopes:** rename CanTransition to InFromStateOf ([e1e42f3](https://github.com/RobertBoes/laravel-interlock/commit/e1e42f39798fbb189311e623f14bf8d69f49929c))
* **scopes:** rename the attribute argument to stateAttribute ([ee0b925](https://github.com/RobertBoes/laravel-interlock/commit/ee0b925657ac1966edb1ce4a89c527b65a2bb804))
* use Laravel's American spelling for authorization ([5c447ef](https://github.com/RobertBoes/laravel-interlock/commit/5c447ef8cf864c7cd7a3cb9a5f40f3827c3c5cae))
