<?php

declare(strict_types=1);

use Coyotito\Ipp\Ipp;

it('exposes a version marker', function () {
    expect(Ipp::VERSION)->toBeString()->not->toBeEmpty();
});
