<?php

namespace Tests\Feature;

class ZvenoAiTaskAssistantTest extends AiTaskAssistantTest
{
    protected const PROVIDER = 'zveno';

    protected const MODEL = 'nvidia/nemotron-3-super-120b-a12b:free';

    protected const URL = 'https://api.zveno.ai/v1/chat/completions';
}
