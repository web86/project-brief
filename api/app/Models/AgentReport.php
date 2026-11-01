<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentReport extends Model
{
    public $timestamps = false;

    public function data(): array
    {
        return ['id' => $this->uuid, 'state' => $this->state, 'pullRequestUrl' => $this->pull_request_url, 'resultUrl' => $this->result_url];
    }
}
