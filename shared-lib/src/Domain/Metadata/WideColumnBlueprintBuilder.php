<?php

namespace CEL\Shared\Domain\Metadata;

class WideColumnBlueprintBuilder
{
    public function build(
    array $metadata,
    array $events,
    array $formEventMapping,
    ?array $selectedForms,
    ?array $selectedFields,
    array $repeatMap
): array 
{

    $fieldsByForm = [];
    $fieldToForm  = [];

    foreach ($metadata as $field) 
    {

        $form = $field['form_name'];
        $fieldName = $field['field_name'];
        $fieldType = $field['field_type'];

        if ($selectedForms && !in_array($form, $selectedForms)) 
        {
            continue;
        }

        if ($selectedFields && !in_array($fieldName, $selectedFields)) 
        {
            continue;
        }

        if ($fieldType === 'checkbox') 
        {

            $choices = explode('|', $field['select_choices_or_calculations']);

            foreach ($choices as $choice) 
            {

                [$value] = explode(',', trim($choice));
                $expanded = "{$fieldName}___" . trim($value);

                $fieldsByForm[$form][] = $expanded;
                $fieldToForm[$expanded] = $form;
            }

        } 
        else 
        {

            $fieldsByForm[$form][] = $fieldName;
            $fieldToForm[$fieldName] = $form;
        }
    }

    $orderedColumns = ['record_id'];

    if (empty($events)) 
    {

        foreach ($fieldsByForm as $form => $fields) {
            foreach ($fields as $field) {
                $orderedColumns[] = "{$form}_{$field}";
            }
        }

        return [
            'columns'     => $orderedColumns,
            'fieldToForm' => $fieldToForm
        ];
    }

    foreach ($events as $event) {

        $eventName = $event['unique_event_name'];

        foreach ($formEventMapping as $map) {

            if ($map['unique_event_name'] !== $eventName) {
                continue;
            }

            $form = $map['form'];

            if (!isset($fieldsByForm[$form])) {
                continue;
            }

            $isRepeating = isset($repeatMap[$eventName][$form]);

            if (!$isRepeating) {

                foreach ($fieldsByForm[$form] as $field) {
                    $orderedColumns[] =
                        "{$eventName}_{$form}_{$field}";
                }

            } else {

                $maxInstance = $repeatMap[$eventName][$form];

                for ($i = 1; $i <= $maxInstance; $i++) {

                    foreach ($fieldsByForm[$form] as $field) {

                        $orderedColumns[] =
                            "{$eventName}_{$form}_{$i}_{$field}";
                    }
                }
            }
        }
    }

    return [
        'columns'     => $orderedColumns,
        'fieldToForm' => $fieldToForm
    ];
}

}
