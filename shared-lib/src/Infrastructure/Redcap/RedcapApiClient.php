<?php

namespace CEL\Shared\Infrastructure\Redcap;

class RedcapApiClient
{
    private string $apiUrl;
    private string $token;
    private ?string $primaryKey = null;

    public function __construct(string $apiUrl, string $token)
    {
        $this->apiUrl = $apiUrl;
        $this->token  = $token;
    }

    /**
     * Stream records in chunks (memory safe)
     */
    public function stream(
    array $fields = [],
    array $forms = [],
    array $events = [],
    int $chunkSize = 200
	): \Generator
	{
		$primaryKey = $this->getPrimaryKey();

		$recordIds = $this->fetchRecordIds();
		$chunks = array_chunk($recordIds, $chunkSize);

		foreach ($chunks as $chunk) 
		{

			$post = [
				'token'        => $this->token,
				'content'      => 'record',
				'format'       => 'json',
				'records'      => $chunk,
				'returnFormat' => 'json'
			];

			/*
			|--------------------------------------------------------------------------
			| Field selection.
			|
			| - If specific fields are requested, send them (plus the primary key,
			|   so rows can always be keyed/grouped).
			| - If specific FORMS are requested (but no explicit fields), do NOT
			|   send a fields list — let the 'forms' filter below decide scope, so
			|   every field on those forms is returned.
			| - If NEITHER fields NOR forms are requested (e.g. WideDump /
			|   FullProjectDump with no filter), omit 'fields' entirely so REDCap
			|   returns ALL fields. In type=flat (the default) a longitudinal
			|   project still returns one row per record-event with
			|   redcap_event_name populated — so we do NOT need to restrict to the
			|   primary key to "force" longitudinal mode. Restricting here was the
			|   cause of dumps returning only record_id with all data blank.
			|--------------------------------------------------------------------------
			*/
			if (!empty($fields))
			{
				if (!in_array($primaryKey, $fields))
				{
					$fields[] = $primaryKey;
				}
				$post['fields'] = $fields;
			}
			// else: no explicit fields — leave 'fields' unset so all fields (or
			// all fields on the requested forms) are returned.

			/*
			|--------------------------------------------------------------------------
			| Optional Form Filtering
			|--------------------------------------------------------------------------
			*/
			if (!empty($forms)) 
			{
				$post['forms'] = $forms;
			}

			/*
			|--------------------------------------------------------------------------
			| Optional Event Filtering (NEW)
			|--------------------------------------------------------------------------
			*/
			if (!empty($events)) 
			{
				$post['events'] = $events;
			}

			$response = $this->post($post);
			$data     = json_decode($response, true);
			$response = null;   // free raw JSON string immediately — halves peak memory per chunk

			if (!is_array($data) || isset($data['error'])) 
			{
				throw new \Exception(
					"REDCap API Error: " . ($data['error'] ?? 'Unknown error')
				);
			}

			foreach ($data as $row) 
			{
				yield $row;
			}
			unset($data);       // free decoded chunk before fetching the next one
		}
	}




    private function post(array $post): string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
        ]);

        $response = curl_exec($ch);

        if ($response === false) 
        {
            throw new \Exception(curl_error($ch));
        }

        // curl_close($ch);

        return $response;
    }
    
    public function fetchRecordIds(): array
	{
		$primaryKey = $this->getPrimaryKey();
		
		$post = [
			'token' => $this->token,
			'content' => 'record',
			'format' => 'json',
			'fields' => [$primaryKey],
			'returnFormat' => 'json'
		];

		$response = $this->post($post);
		$data = json_decode($response, true);

		$ids = array_column($data, $primaryKey);
		
		return array_values(array_unique($ids));

	}
	
	public function fetchMetadata(): array
	{
		$post = [
			'token'        => $this->token,
			'content'      => 'metadata',
			'format'       => 'json',
			'returnFormat' => 'json'
		];

		$response = $this->post($post);

		return json_decode($response, true) ?? [];
	}
	
	public function fetchEvents(): array
	{
		$post = [
			'token'        => $this->token,
			'content'      => 'event',
			'format'       => 'json',
			'returnFormat' => 'json'
		];

		$response = $this->post($post);

		return json_decode($response, true) ?? [];
	}

	public function fetchFormEventMapping(): array
	{
		$post = [
			'token'        => $this->token,
			'content'      => 'formEventMapping',
			'format'       => 'json',
			'returnFormat' => 'json'
		];

		$response = $this->post($post);

		return json_decode($response, true) ?? [];
	}
	
	public function getPrimaryKey(): string
	{
		if ($this->primaryKey !== null) 
		{
			return $this->primaryKey;
		}

		$metadata = $this->fetchMetadata();

		if (empty($metadata)) 
		{
			throw new \Exception("Unable to determine primary key from metadata.");
		}

		$this->primaryKey = $metadata[0]['field_name'];

		return $this->primaryKey;
	}




    /**
     * Fetch the coded value => display label map for a dropdown/radio field.
     *
     * REDCap stores choices as a pipe-delimited string, e.g.:
     *   "GSVM, GSVM Kanpur | JSS, JSS Mysore | NILOU, Niloufer Hyderabad"
     *
     * Returns: ['GSVM' => 'GSVM Kanpur', 'JSS' => 'JSS Mysore', ...]
     */
    public function fetchFieldChoices(string $fieldName): array
    {
        $metadata = $this->fetchMetadata();

        foreach ($metadata as $field)
        {
            if ($field['field_name'] !== $fieldName) continue;

            $raw = $field['select_choices_or_calculations'] ?? '';
            if (empty($raw)) return [];

            $choices = [];
            foreach (explode('|', $raw) as $choice)
            {
                $parts = explode(',', trim($choice), 2);
                if (count($parts) === 2)
                {
                    $choices[trim($parts[0])] = trim($parts[1]);
                }
            }

            return $choices;
        }

        return [];
    }

}
