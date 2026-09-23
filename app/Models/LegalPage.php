<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Privacy policy and terms of use shown on the public site, editable in admin. */
class LegalPage extends Model
{
    protected $fillable = ['slug', 'title', 'body_html', 'updated_by'];
}
