<?php

namespace App\Domain\Customers\ProductionIdentity;

/** Fixed source stages; no caller family/SQL/schema selection. */
enum CatalogGroup: string
{
    case A0_ACCOUNT_TABLE = 'A0_ACCOUNT_TABLE';
    case A1_ACCOUNT_AFTER_SHOW = 'A1_ACCOUNT_AFTER_SHOW';
    case A2_ACCOUNT_FLOOR = 'A2_ACCOUNT_FLOOR';
    case P0_PARENT_NAMESPACE = 'P0_PARENT_NAMESPACE';
    case P1_PARENT_SHAPE = 'P1_PARENT_SHAPE';
    case P2_PARENT_GUARDS = 'P2_PARENT_GUARDS';
    case I0_IDENTITY_NAMESPACE = 'I0_IDENTITY_NAMESPACE';
    case I1_IDENTITY_SHAPE = 'I1_IDENTITY_SHAPE';
    case I2_RESERVED_NAMES = 'I2_RESERVED_NAMES';
    case D0_FULL_DEPENDENTS = 'D0_FULL_DEPENDENTS';
}
