<?php
/**
 * Web services PrestaShop module - Perithori
 * @author    tivuno.com <hi@tivuno.com>
 * @copyright 2018 - 2025 © tivuno.com
 * @license   https://tivuno.com/el/blog/nea-tis-epicheirisis/apli-adeia
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
class WebserviceRequest extends WebserviceRequestCore
{
    protected function saveEntityFromXml($successReturnCode): bool
    {
        try {
            $xml = new SimpleXMLElement($this->_inputXml);
        } catch (Exception $error) {
            $this->setError(500, 'XML error : ' . $error->getMessage() . "\n" . 'XML length : ' . strlen($this->_inputXml) . "\n" . 'Original XML : ' . $this->_inputXml, 127);

            return false;
        }

        $xmlEntities = $xml->children();
        $object = null;

        $ids = [];
        foreach ($xmlEntities as $entity) {
            // To cast in string allow to check null values
            if ((string) $entity->id != '') {
                $ids[] = (int) $entity->id;
            }
        }
        if ($this->method == 'PUT' || $this->method == 'PATCH') {
            $ids2 = array_unique($ids);
            if (count($ids2) != count($ids)) {
                $this->setError(400, 'id is duplicate in request', 89);

                return false;
            }
            if (count($xmlEntities) != count($ids)) {
                $this->setError(400, 'id is required when modifying a resource', 90);

                return false;
            }
        } elseif ($this->method == 'POST' && count($ids) > 0) {
            $this->setError(400, 'id is forbidden when adding a new resource', 91);

            return false;
        }

        $postponeNTreeRegeneration = false;
        foreach ($xmlEntities as $xmlEntity) {
            /** @var SimpleXMLElement $xmlEntity */
            $attributes = $xmlEntity->children();

            /* @var ObjectModel $object */
            if ($this->method == 'POST') {
                $object = new $this->resourceConfiguration['retrieveData']['className']();
            } elseif ($this->method == 'PUT' || $this->method == 'PATCH') {
                $object = new $this->resourceConfiguration['retrieveData']['className']((int) $attributes->id);
                if (!$object->id) {
                    $this->setError(404, 'Invalid ID', 92);

                    return false;
                }
            }

            if ($object instanceof Category) {
                $object->doNotRegenerateNTree = true;
                $postponeNTreeRegeneration = true;
            }

            $this->objects[] = $object;
            $i18n = false;
            // attributes
            foreach ($this->resourceConfiguration['fields'] as $fieldName => $fieldProperties) {
                // only process fields actually in the input XML
                if ($this->method == 'PATCH' && !isset($attributes->$fieldName)) {
                    continue;
                }
                $sqlId = $fieldProperties['sqlId'];
                if ($fieldName == 'id') {
                    $sqlId = $fieldName;
                }
                if (isset($attributes->$fieldName, $fieldProperties['sqlId']) && (!isset($fieldProperties['i18n']) || !$fieldProperties['i18n'])) {
                    if (isset($fieldProperties['setter'])) {
                        // if we have to use a specific setter
                        if (!$fieldProperties['setter']) {
                            // if it's forbidden to set this field
                            $this->setError(400, 'parameter "' . $fieldName . '" not writable. Please remove this attribute of this XML', 93);

                            return false;
                        } else {
                            $object->{$fieldProperties['setter']}((string) $attributes->$fieldName);
                        }
                    } elseif (property_exists($object, $sqlId)) {
                        $object->$sqlId = (string) $attributes->$fieldName;
                    } else {
                        $this->setError(400, 'Parameter "' . $fieldName . '" can\'t be set to the object "' . $this->resourceConfiguration['retrieveData']['className'] . '"', 123);
                    }
                } elseif (isset($fieldProperties['required']) && $fieldProperties['required'] && !$fieldProperties['i18n']) {
                    $this->setError(400, 'parameter "' . $fieldName . '" required', 41);

                    return false;
                } elseif ((!isset($fieldProperties['required']) || !$fieldProperties['required']) && property_exists($object, $sqlId)) {
                    $object->$sqlId = null;
                }
                if (isset($fieldProperties['i18n']) && $fieldProperties['i18n']) {
                    $i18n = true;
                    if (isset($attributes->$fieldName, $attributes->$fieldName->language)) {
                        foreach ($attributes->$fieldName->language as $lang) {
                            /* @var SimpleXMLElement $lang */
                            $object->{$fieldName}[(int) $lang->attributes()->id] = (string) $lang;
                        }
                    } else {
                        $object->{$fieldName} = (string) $attributes->$fieldName;
                    }
                }
            }

            // Apply the modifiers if they exist
            foreach ($this->resourceConfiguration['fields'] as $fieldName => $fieldProperties) {
                if (isset($fieldProperties['modifier']['modifier']) && $fieldProperties['modifier']['http_method'] & constant('WebserviceRequest::HTTP_' . $this->method)) {
                    $object->{$fieldProperties['modifier']['modifier']}();
                }
            }

            if (!$this->hasErrors()) {
                if ($i18n && ($retValidateFieldsLang = $object->validateFieldsLang(false, true)) !== true) {
                    $this->setError(400, 'Validation error: "' . $retValidateFieldsLang . '"', 84);

                    return false;
                } elseif (($retValidateFields = $object->validateFields(false, true)) !== true) {
                    $this->setError(400, 'Validation error: "' . $retValidateFields . '"', 85);

                    return false;
                } else {
                    // Call alternative method for add/update
                    $objectMethod = ($this->method == 'POST' ? 'add' : 'update');
                    if (isset($this->resourceConfiguration['objectMethods']) && array_key_exists($objectMethod, $this->resourceConfiguration['objectMethods'])) {
                        $objectMethod = $this->resourceConfiguration['objectMethods'][$objectMethod];
                    }

                    // It’s either add or update
                    $hook_name = 'actionObject' . self::getFullyQualifiedName($object) . ucfirst($this->method);
                    Hook::exec($hook_name . 'Before', ['object' => $object]);
                    $result = $object->{$objectMethod}();
                    Hook::exec($hook_name . 'After', ['object' => $object]);
                    if ($result) {
                        if (isset($attributes->associations)) {
                            foreach ($attributes->associations->children() as $association) {
                                /** @var SimpleXMLElement $association */
                                // associations
                                if (isset($this->resourceConfiguration['associations'][$association->getName()])) {
                                    $assocItems = $association->children();
                                    $values = [];
                                    foreach ($assocItems as $assocItem) {
                                        /** @var SimpleXMLElement $assocItem */
                                        $fields = $assocItem->children();
                                        $entry = [];
                                        foreach ($fields as $fieldName => $fieldValue) {
                                            $entry[$fieldName] = (string) $fieldValue;
                                        }
                                        $values[] = $entry;
                                    }
                                    $setter = $this->resourceConfiguration['associations'][$association->getName()]['setter'];
                                    if (null !== $setter && $setter && method_exists($object, $setter) && !$object->$setter($values)) {
                                        $this->setError(500, 'Error occurred while setting the ' . $association->getName() . ' value', 85);

                                        return false;
                                    }
                                } elseif ($association->getName() != 'i18n') {
                                    $this->setError(400, 'The association "' . $association->getName() . '" does not exists', 86);

                                    return false;
                                }
                            }
                        }
                        $assoc = Shop::getAssoTable($this->resourceConfiguration['retrieveData']['table']);
                        if ($assoc !== false && $assoc['type'] != 'fk_shop') {
                            // PUT nor POST is destructive, no deletion
                            $sql = 'INSERT IGNORE INTO `' . bqSQL(_DB_PREFIX_ . $this->resourceConfiguration['retrieveData']['table'] . '_' . $assoc['type']) . '` (id_shop, `' . bqSQL($this->resourceConfiguration['fields']['id']['sqlId']) . '`) VALUES ';
                            foreach (self::$shopIDs as $id) {
                                $sql .= '(' . (int) $id . ',' . (int) $object->id . ')';
                                if ($id != end(self::$shopIDs)) {
                                    $sql .= ', ';
                                }
                            }
                            Db::getInstance()->execute($sql);
                        }
                    } else {
                        $this->setError(500, 'Unable to save resource', 46);
                    }
                }
            }
        }

        if ($postponeNTreeRegeneration) {
            Category::regenerateEntireNtree();
        }

        if (!$this->hasErrors()) {
            $this->objOutput->setStatus($successReturnCode);

            return true;
        }

        return false;
    }

    public static function getFullyQualifiedName(object $object): array|string
    {
        return str_replace('\\', '', get_class($object));
    }
}
