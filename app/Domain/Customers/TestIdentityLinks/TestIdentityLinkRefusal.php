<?php

namespace App\Domain\Customers\TestIdentityLinks;

use RuntimeException;

/** A refusal of the test identity link relay. Messages are operator-facing and carry no path, proof, link or secret. */
final class TestIdentityLinkRefusal extends RuntimeException {}
