<?php

namespace App\Enums;

enum AgentCountSource: string
{
    case Onboarding = 'onboarding';
    case Admin = 'admin';
    case ApiPush = 'api_push';
    case ApiPull = 'api_pull';
}
