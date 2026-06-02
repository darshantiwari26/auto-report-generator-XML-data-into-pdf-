<?php

function esc($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function readUploadedXml()
{
    if (!isset($_FILES['xml_file']) || $_FILES['xml_file']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please choose a valid XML file.');
    }

    $extension = strtolower(pathinfo($_FILES['xml_file']['name'], PATHINFO_EXTENSION));
    if ($extension !== 'xml') {
        throw new RuntimeException('Only .xml files are supported.');
    }

    $contents = file_get_contents($_FILES['xml_file']['tmp_name']);
    if ($contents === false || trim($contents) === '') {
        throw new RuntimeException('The uploaded XML file is empty or unreadable.');
    }

    return $contents;
}

function xmlToReport($xmlString, $fileName = 'Uploaded XML')
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);

    if ($xml === false) {
        $errors = array_map(function ($error) {
            return trim($error->message);
        }, libxml_get_errors());
        libxml_clear_errors();
        throw new RuntimeException('Invalid XML: ' . implode(' ', array_filter($errors)));
    }

    $records = extractRecords($xml);
    $metrics = [
        'Root Element' => $xml->getName(),
        'Generated On' => date('d M Y, h:i A'),
        'Source File' => $fileName,
        'Record Groups' => count($records),
    ];

    return [
        'title' => 'NIET Auto Report Generator',
        'root' => $xml->getName(),
        'metrics' => $metrics,
        'attributes' => attributesToArray($xml),
        'overview' => directTextFields($xml),
        'records' => $records,
        'raw_xml' => $xmlString,
    ];
}

function attributesToArray(SimpleXMLElement $node)
{
    $attributes = [];
    foreach ($node->attributes() as $name => $value) {
        $attributes[$name] = (string) $value;
    }
    return $attributes;
}

function directTextFields(SimpleXMLElement $node)
{
    $fields = [];
    foreach ($node->children() as $name => $child) {
        if ($child->count() === 0) {
            $value = trim((string) $child);
            if ($value !== '') {
                $fields[$name] = $value;
            }
        }
    }
    return $fields;
}

function extractRecords(SimpleXMLElement $root)
{
    $groups = [];
    foreach ($root->children() as $name => $child) {
        if ($child->count() > 0) {
            $rows = [];

            if (childrenShareNames($child)) {
                foreach ($child->children() as $rowNode) {
                    $rows[] = flattenNode($rowNode);
                }
            } else {
                $rows[] = flattenNode($child);
            }

            if (!empty($rows)) {
                $groups[] = [
                    'name' => humanize($name),
                    'rows' => $rows,
                    'columns' => collectColumns($rows),
                ];
            }
        }
    }
    return $groups;
}

function childrenShareNames(SimpleXMLElement $node)
{
    $names = [];
    foreach ($node->children() as $child) {
        $names[] = $child->getName();
    }
    return count($names) > 1 && count(array_unique($names)) === 1;
}

function flattenNode(SimpleXMLElement $node, $prefix = '')
{
    $row = [];

    foreach ($node->attributes() as $name => $value) {
        $row[trim($prefix . '@' . $name, '.')] = (string) $value;
    }

    if ($node->count() === 0) {
        $value = trim((string) $node);
        if ($value !== '') {
            $row[trim($prefix . $node->getName(), '.')] = $value;
        }
        return $row;
    }

    foreach ($node->children() as $name => $child) {
        $key = $prefix === '' ? $name : $prefix . '.' . $name;
        if ($child->count() === 0) {
            $row[$key] = trim((string) $child);
            foreach ($child->attributes() as $attr => $value) {
                $row[$key . '.@' . $attr] = (string) $value;
            }
        } else {
            $row = array_merge($row, flattenNode($child, $key));
        }
    }

    return $row;
}

function collectColumns(array $rows)
{
    $columns = [];
    foreach ($rows as $row) {
        foreach (array_keys($row) as $column) {
            $columns[$column] = true;
        }
    }
    return array_keys($columns);
}

function humanize($value)
{
    $value = preg_replace('/([a-z])([A-Z])/', '$1 $2', (string) $value);
    $value = str_replace(['_', '-'], ' ', $value);
    return ucwords(trim($value));
}

