<?php

namespace NineteenNinetyFour\SignOff\Tests\Feature;

/**
 * The same flow, with submissions in the database.
 */
class DatabaseSignOffFlowTest extends SignOffFlowTest
{
    protected string $driver = 'database';
}
