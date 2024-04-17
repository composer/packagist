<?php

namespace App\Security\Voter;

enum PackageActions: string
{
    case Edit = 'edit';
    case AddMaintainer = 'add_maintainer';
    case RemoveMaintainer = 'remove_maintainer';
    case Abandon = 'abandon';
    case Delete = 'delete';
    case DeleteVersion = 'delete_version';
    case Update = 'update';
}
