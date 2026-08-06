<?php

declare(strict_types=1);

namespace Illuminate\Database\Eloquent {
    /**
     * A stand-in for an Eloquent model, so the mapping between Ruk Lab's
     * vocabulary and a site's columns can be exercised without booting a
     * framework it does not otherwise need. It reads attributes the way the
     * real one does, and that is all this code ever asks of a model.
     */
    class Model
    {
        /** @var array<string, mixed> */
        protected $attributes = [];

        public function __get(string $name): mixed
        {
            return $this->attributes[$name] ?? null;
        }
    }
}

namespace {
    /**
     * The mapping reads one config value, the site URL, and nothing else.
     */
    function config(string $key, mixed $default = null): mixed
    {
        return $key === 'app.url' ? 'https://cierzo.test' : $default;
    }

    require_once __DIR__.'/../src/Support/Value.php';
    require_once __DIR__.'/../src/Support/ConnectorException.php';
    require_once __DIR__.'/../src/Content/ContentType.php';
}
