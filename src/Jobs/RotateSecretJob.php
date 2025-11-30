<?php

namespace BrainletAli\Locksmith\Jobs;

use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Facades\Locksmith;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Queueable job for async secret rotation. */
class RotateSecretJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $key,
        public string $recipeClass,
        public int $gracePeriod = 60
    ) {}

    /** Execute the job. */
    public function handle(): void
    {
        if (! Locksmith::has($this->key)) {
            return;
        }

        /** @var Recipe $recipe */
        $recipe = app($this->recipeClass);

        Locksmith::rotate($this->key, $recipe, $this->gracePeriod);
    }
}
