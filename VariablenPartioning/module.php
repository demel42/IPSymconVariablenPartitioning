<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class VariablenPartioning extends IPSModule
{
    use VariablenPartioning\StubsCommonLib;
    use VariablenPartioningLocalLib;

    private static $semaphoreID = __CLASS__;
    private static $semaphoreTM = 5 * 1000;

    public static $null_destination = '-';

    public static $ident_var_pfx = 'VAR_';
    public static $ident_sub_pfx = 'SUB_';

    private $ModuleDir;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->ModuleDir = __DIR__;
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyInteger('source_varID', 0);
        $this->RegisterPropertyString('destinations', json_encode([]));

        $this->RegisterAttributeInteger('variableType', 0);
        $this->RegisterAttributeInteger('aggregationType', 0);
        $this->RegisterAttributeString('variableData', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $destination = $this->GetValue('Destination');
            if ($destination != '' && $destination != self::$null_destination) {
                $variableData = json_decode($this->ReadAttributeString('variableData'), true);
                if (isset($variableData[$destination]) == false) {
                    $this->ChangeDestination($destination);
                }
            }
        }

        if (IPS_GetKernelRunlevel() == KR_READY && $message == VM_UPDATE && $data[1] == true /* changed */) {
            $this->SendDebug(__FUNCTION__, 'timestamp=' . $timestamp . ', senderID=' . $senderID . ', message=' . $message . ', data=' . print_r($data, true), 0);
            $this->UpdateVariable($data[0], $data[2], $data[1]);
        }
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $source_varID = $this->ReadPropertyInteger('source_varID');
        if (IPS_VariableExists($source_varID) == false) {
            $this->SendDebug(__FUNCTION__, '"source_varID" is needed', 0);
            $r[] = $this->Translate('Source variable must be specified');
        }

        $destinations = json_decode($this->ReadPropertyString('destinations'), true);
        if ($destinations != false) {
            $identList = [];
            foreach ($destinations as $destination) {
                $ident = $destination['ident'];
                if ($ident == '') {
                    $this->SendDebug(__FUNCTION__, '"ident" in "destinations" is needed', 0);
                    $r[] = $this->Translate('Column "ident" in field "destinations" must be not empty');
                    continue;
                }
                if (in_array($ident, $identList)) {
                    $this->SendDebug(__FUNCTION__, 'duplicate "ident" in "destinations"', 0);
                    $r[] = $this->Translate('Column "ident" in field "destinations" must be unique');
                }
                $identList[] = $ident;

                $name = $destination['name'];
                if ($name == '') {
                    $this->SendDebug(__FUNCTION__, '"name" in "destinations" is needed', 0);
                    $r[] = $this->Translate('Column "name" in field "destinations" must be not empty');
                }
            }
        }

        return $r;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = ['source_varID'];
        $this->MaintainReferences($propertyNames);

        $varIDs = [];
        foreach ($propertyNames as $name) {
            $varIDs[] = $this->ReadPropertyInteger($name);
        }

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $destinations = json_decode($this->ReadPropertyString('destinations'), true);
        $associations = [
            [
                'Wert'  => self::$null_destination,
                'Name'  => $this->Translate('no selection'),
                'Farbe' => 0x595959,
            ],
        ];
        foreach ($destinations as $destination) {
            if (isset($destination['inactive']) && $destination['inactive']) {
                continue;
            }
            $associations[] = [
                'Wert'  => $destination['ident'],
                'Name'  => $destination['name'],
                'Farbe' => 0xFFFF00,
            ];
        }
        $this->SendDebug(__FUNCTION__, 'associations=' . print_r($associations, true), 0);
        $variableProfile = 'VariablenPartioning_' . $this->InstanceID . '.Destinations';
        $this->CreateVarProfile($variableProfile, VARIABLETYPE_STRING, '', 0, 0, 0, 0, '', $associations, true);

        $vpos = 1;
        $this->MaintainVariable('Destination', $this->Translate('Destination'), VARIABLETYPE_STRING, $variableProfile, $vpos++, true);
        $this->MaintainAction('Destination', true);

        $vpos_base = 10;

        $source_varID = $this->ReadPropertyInteger('source_varID');
        if (IPS_VariableExists($source_varID)) {
            $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];

            $var = IPS_GetVariable($source_varID);
            $variableType = $var['VariableType'];
            $variableProfile = $var['VariableProfile'];
            $variableCustomProfile = $var['VariableCustomProfile'];
            $loggingStatus = AC_GetLoggingStatus($archivID, $source_varID);
            $aggregationType = AC_GetAggregationType($archivID, $source_varID);
            $ignoreZeros = AC_GetCounterIgnoreZeros($archivID, $source_varID);

            $this->WriteAttributeInteger('variableType', $variableType);
            $this->WriteAttributeInteger('aggregationType', $aggregationType);

            $varList = [];
            foreach ($destinations as $destination) {
                $vpos = $vpos_base;
                $vpos_base += 10;

                $ident = self::$ident_var_pfx . $destination['ident'];
                $name = $destination['name'];
                $this->MaintainVariable($ident, $name, $variableType, $variableProfile, $vpos++, true);
                $varList[] = $ident;

                $varID = $this->GetIDForIdent($ident);

                IPS_SetName($varID, $name);
                IPS_SetVariableCustomProfile($varID, $variableCustomProfile);

                $reAggregate = AC_GetLoggingStatus($archivID, $source_varID) == false || AC_GetAggregationType($archivID, $source_varID) != $aggregationType;
                AC_SetLoggingStatus($archivID, $varID, $loggingStatus);
                if ($loggingStatus) {
                    AC_SetAggregationType($archivID, $varID, $aggregationType);
                    AC_SetCounterIgnoreZeros($archivID, $varID, $ignoreZeros);
                    if ($reAggregate) {
                        AC_ReAggregateVariable(archivID, $varID);
                    }
                }

                $subtotal = $destination['subtotal'];
                if ($subtotal) {
                    $ident = self::$ident_sub_pfx . $destination['ident'];
                    $name = $destination['name'] . ' (' . $this->Translate('subtotal') . ')';
                    $this->MaintainVariable($ident, $name, $variableType, $variableProfile, $vpos++, true);
                    $varList[] = $ident;

                    $varID = $this->GetIDForIdent($ident);

                    IPS_SetName($varID, $name);
                    IPS_SetVariableCustomProfile($varID, $variableCustomProfile);

                    $reAggregate = AC_GetLoggingStatus($archivID, $source_varID) == false;
                    AC_SetLoggingStatus($archivID, $varID, $loggingStatus);
                    if ($loggingStatus) {
                        AC_SetAggregationType($archivID, $varID, $aggregationType);
                        AC_SetCounterIgnoreZeros($archivID, $varID, $ignoreZeros);
                        if ($reAggregate) {
                            AC_ReAggregateVariable(archivID, $varID);
                        }
                    }
                }
            }

            $objList = [];
            $this->findVariables($this->InstanceID, $objList);
            foreach ($objList as $obj) {
                $ident = $obj['ObjectIdent'];
                if (!in_array($ident, $varList)) {
                    $this->SendDebug(__FUNCTION__, 'unregister variable: ident=' . $ident, 0);
                    $this->UnregisterVariable($ident);
                }
            }
        }

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        foreach ($varIDs as $varID) {
            if (IPS_VariableExists($varID)) {
                $this->RegisterMessage($varID, VM_UPDATE);
            }
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $destination = $this->GetValue('Destination');
            if ($destination != '' && $destination != self::$null_destination) {
                $variableData = json_decode($this->ReadAttributeString('variableData'), true);
                $this->SendDebug(__FUNCTION__, 'destination=' . $destination, 0);
                if (isset($variableData[$destination]) == false) {
                    $this->ChangeDestination($destination);
                }
            }
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Variablen partitioning');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance'
        ];

        $formElements[] = [
            'name'    => 'source_varID',
            'type'    => 'SelectVariable',
            'width'   => '500px',
            'caption' => 'Source variable',
        ];

        $formElements[] = [
            'name'    => 'destinations',
            'type'    => 'List',
            'add'     => true,
            'delete'  => true,
            'columns' => [
                [
                    'name'    => 'ident',
                    'add'     => '',
                    'edit'    => [
                        'type'     => 'ValidationTextBox',
                        'validate' => '^[0-9A-Za-z]+$',
                    ],
                    'width'   => '200px',
                    'caption' => 'Ident',
                ],
                [
                    'name'    => 'name',
                    'add'     => '',
                    'edit'    => [
                        'type'    => 'ValidationTextBox',
                    ],
                    'width'   => 'auto',
                    'caption' => 'Name',
                ],
                [
                    'name'    => 'subtotal',
                    'add'     => false,
                    'edit'    => [
                        'type'    => 'CheckBox',
                    ],
                    'width'   => '200px',
                    'caption' => 'Subtotal',
                ],
                [
                    'name'    => 'inactive',
                    'add'     => false,
                    'edit'    => [
                        'type'    => 'CheckBox',
                    ],
                    'width'   => '100px',
                    'caption' => 'inactive',
                ],
            ],
            'caption' => 'Destinations',
        ];
        $formElements[] = [
            'type'    => 'Label',
            'caption' => 'The settings of the source variable are transferred to the target variables',
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $destination_options = [];
        $destinations = json_decode($this->ReadPropertyString('destinations'), true);
        foreach ($destinations as $destination) {
            if (isset($destination['inactive']) && $destination['inactive']) {
                continue;
            }
            $destination_options[] = [
                'value'   => $destination['ident'],
                'caption' => $destination['name'],
            ];
        }

        $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        $avars = AC_GetAggregationVariables($archivID, false);

        $variableData = json_decode($this->ReadAttributeString('variableData'), true);
        $offsetValues = [];
        foreach ($variableData as $dest => $val) {
            @$varID = $this->GetIDForIdent(self::$ident_var_pfx . $dest);
            $firstTime = 0;
            $lastTime = 0;
            $recordCount = 0;
            foreach ($avars as $avar) {
                if ($avar['VariableID'] == $varID) {
                    $firstTime = $avar['FirstTime'];
                    $lastTime = $avar['LastTime'];
                    $recordCount = $avar['RecordCount'];
                    break;
                }
            }

            $offsetValues[] = [
                'destination'  => $dest,
                'src_value'    => $val['src_value'],
                'updated'      => ($val['updated'] ? date('d.m.Y H:i:s', $val['updated']) : '-'),
                'offset'       => (isset($val['offset']) ? $val['offset'] : ''),
                'firstTime'    => ($firstTime ? date('d.m.Y H:i:s', $firstTime) : '-'),
                'lastTime'     => ($lastTime ? date('d.m.Y H:i:s', $lastTime) : '-'),
                'recordCount'  => $recordCount,
            ];
        }
        $this->SendDebug(__FUNCTION__, 'offsetValues=' . print_r($offsetValues, true), 0);

        $variableType = $this->ReadAttributeInteger('variableType');
        switch ($variableType) {
            case VARIABLETYPE_INTEGER:
            case VARIABLETYPE_FLOAT:
                $formActions[] = [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'      => 'Button',
                            'caption'   => 'Build subtotal',
                            'onClick'   => $this->GetModulePrefix() . '_SubtotalBuild($id);'
                        ],
                        [
                            'type'      => 'Button',
                            'caption'   => 'Initialize subtotal',
                            'onClick'   => $this->GetModulePrefix() . '_SubtotalInitialize($id);'
                        ],
                    ],
                ];
                break;
        }

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded ' => false,
            'items'     => [
                [
                    'type'     => 'List',
                    'add'      => false,
                    'delete'   => false,
                    'columns'  => [
                        [
                            'name'     => 'destination',
                            'width'    => 'auto',
                            'caption'  => 'Destination',
                        ],
                        [
                            'name'     => 'src_value',
                            'width'    => '200px',
                            'caption'  => 'Initial source value',
                        ],
                        [
                            'name'     => 'updated',
                            'width'    => '250px',
                            'caption'  => 'Initial source timestamp',
                        ],
                        [
                            'name'     => 'offset',
                            'width'    => '200px',
                            'caption'  => 'Offset from source',
                        ],
                        [
                            'name'     => 'firstTime',
                            'width'    => '200px',
                            'caption'  => 'First destination value',
                        ],
                        [
                            'name'     => 'lastTime',
                            'width'    => '200px',
                            'caption'  => 'Last destination value',
                        ],
                        [
                            'name'     => 'recordCount',
                            'width'    => '100px',
                            'caption'  => 'Count',
                        ],
                    ],
                    'rowCount' => count($offsetValues) > 0 ? count($offsetValues) : 1,
                    'values'   => $offsetValues,
                    'caption'  => 'Variable data',
                ],
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'    => 'Select',
                            'options' => $destination_options,
                            'name'    => 'destination',
                            'caption' => 'Destination'
                        ],
                        [
                            'type'    => 'SelectDateTime',
                            'name'    => 'start_tm',
                            'caption' => 'Start time'
                        ],
                        [
                            'type'    => 'SelectDateTime',
                            'name'    => 'end_tm',
                            'caption' => 'End time'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => '(Re-)partioning archive data from the source variable',
                            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "RepartioningVariable", json_encode(["destination" => $destination, "start_tm" => $start_tm, "end_tm" => $end_tm]));',
                            'confirm' => 'This clears the values of destination variable and re-creates it from source variable',
                        ],
                    ],
                ],
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'    => 'Select',
                            'options' => $destination_options,
                            'name'    => 'destination',
                            'caption' => 'Destination'
                        ],
                        [
                            'type'     => 'ValidationTextBox',
                            'validate' => '^[0-9A-Za-z]+$',
                            'name'     => 'new_ident',
                            'caption'  => 'New ident'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Change the ident of a used variable',
                            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "ChangeIdent", json_encode(["destination" => $destination, "new_ident" => $new_ident]));',
                        ],
                    ],
                ],
            ],
        ];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Test area',
            'expanded ' => false,
            'items'     => [
                [
                    'type'    => 'TestCenter',
                ],
            ]
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    private function ChangeDestination($destination)
    {
        if ($destination == '' || $destination == self::$null_destination) {
            $this->SendDebug(__FUNCTION__, 'destination is not set', 0);
            return true;
        }

        $variableData = json_decode($this->ReadAttributeString('variableData'), true);
        if ($this->GetValue('Destination') == $destination && isset($variableData[$destination])) {
            $this->SendDebug(__FUNCTION__, 'destination "' . $destination . '" keep unchanged', 0);
            return false;
        }

        $variableData = json_decode($this->ReadAttributeString('variableData'), true);
        $this->SendDebug(__FUNCTION__, 'old offset=' . print_r($variableData, true), 0);

        $source_varID = $this->ReadPropertyInteger('source_varID');
        $var = IPS_GetVariable($source_varID);
        $srcValue = GetValue($source_varID);

        $ident = self::$ident_var_pfx . $destination;
        $dstValue = $this->GetValue($ident);

        $e = [
            'src_value' => $srcValue,
            'updated'   => $var['VariableUpdated'],
        ];

        $aggregationType = $this->ReadAttributeInteger('aggregationType');
        if ($aggregationType == 1 /* Zähler */) {
            $variableType = $this->ReadAttributeInteger('variableType');
            switch ($variableType) {
                case VARIABLETYPE_INTEGER:
                    $e['offset'] = (int) $srcValue - (int) $dstValue;
                    break;
                case VARIABLETYPE_FLOAT:
                    $e['offset'] = (float) $srcValue - (float) $dstValue;
                    break;
            }
        }

        $variableData[$destination] = $e;
        $this->SendDebug(__FUNCTION__, 'new offset=' . print_r($variableData, true), 0);
        $this->WriteAttributeString('variableData', json_encode($variableData));

        return true;
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'RepartioningVariable':
                $this->RepartioningVariable($value);
                break;
            case 'ChangeIdent':
                $this->ChangeIdent($value);
                break;
            default:
                $r = false;
                break;
        }
        return $r;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->LocalRequestAction($ident, $value)) {
            return;
        }
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }

        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', value=' . $value, 0);

        $r = false;
        switch ($ident) {
            case 'Destination':
                $r = $this->ChangeDestination($value);
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
        if ($r) {
            $this->SetValue($ident, $value);
        }
    }

    private function UpdateVariable($value, $oldValue, $changed)
    {
        $this->SendDebug(__FUNCTION__, 'value=' . $value . ', oldValue=' . $oldValue . ', changed=' . $this->bool2str($changed), 0);

        if ($changed) {
            $destination = $this->GetValue('Destination');
            if ($destination != '' && $destination != self::$null_destination) {
                $ident = self::$ident_var_pfx . $destination;
                $aggregationType = $this->ReadAttributeInteger('aggregationType');
                if ($aggregationType == 1 /* Zähler */) {
                    $variableData = json_decode($this->ReadAttributeString('variableData'), true);
                    $variableType = $this->ReadAttributeInteger('variableType');
                    switch ($variableType) {
                        case VARIABLETYPE_INTEGER:
                            $offset = isset($variableData[$destination]['offset']) ? (int) $variableData[$destination]['offset'] : 0;
                            if ($offset) {
                                $value = (int) $value - (int) $offset;
                                $this->SendDebug(__FUNCTION__, 'calc value: offset=' . $offset . ' => new value=' . $value, 0);
                            } else {
                                $diff = (int) $value - (int) $oldValue;
                                $value = (int) $this->GetValue($ident) + $diff;
                                $this->SendDebug(__FUNCTION__, 'calc value: diff=' . $diff . ' => new value=' . $value, 0);
                            }
                            break;
                        case VARIABLETYPE_FLOAT:
                            $offset = isset($variableData[$destination]['offset']) ? (float) $variableData[$destination]['offset'] : 0;
                            if ($offset) {
                                $value = (float) $value - (float) $offset;
                                $this->SendDebug(__FUNCTION__, 'calc value: offset=' . $offset . ' => new value=' . $value, 0);
                            } else {
                                $diff = (float) $value - (float) $oldValue;
                                $value = (float) $this->GetValue($ident) + $diff;
                                $this->SendDebug(__FUNCTION__, 'calc value: diff=' . $diff . ' => new value=' . $value, 0);
                            }
                            break;
                    }
                }
                $this->SetValue($ident, $value);
                $this->SendDebug(__FUNCTION__, 'change variable "' . $ident . '", set value to ' . $value, 0);
            } else {
                $this->SendDebug(__FUNCTION__, 'no destination selected', 0);
            }
        }
    }

    private function cmp_val($a, $b)
    {
        return ($a['TimeStamp'] < $b['TimeStamp']) ? -1 : 1;
    }

    private function RepartioningVariable($args)
    {
        $this->SendDebug(__FUNCTION__, 'args=' . $args, 0);
        $jargs = json_decode($args, true);

        $destination = $jargs['destination'];

        $now = time();

        $start_tm = json_decode($jargs['start_tm'], true);
        if ($start_tm['year'] > 0) {
            $start_ts = mktime($start_tm['hour'], $start_tm['minute'], $start_tm['second'], $start_tm['month'], $start_tm['day'], $start_tm['year']);
        } else {
            $start_ts = 0;
        }

        $end_tm = json_decode($jargs['end_tm'], true);
        if ($end_tm['year'] > 0) {
            $end_ts = mktime($end_tm['hour'], $end_tm['minute'], $end_tm['second'], $end_tm['month'], $end_tm['day'], $end_tm['year']);
        } else {
            $end_ts = $now;
        }

        $startS = $start_ts ? date('d.m.Y H:i:s', $start_ts) : '-';
        $endS = $end_ts ? date('d.m.Y H:i:s', $end_ts) : '-';
        $this->SendDebug(__FUNCTION__, 'destination=' . $destination . ', start=' . $startS . ', end=' . $endS, 0);

        $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];

        $msg = '';

        $do = true;

        if ($do) {
            $varID_src = $this->ReadPropertyInteger('source_varID');
            if ($varID_src == false) {
                $s = 'no source variable';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
            $this->SendDebug(__FUNCTION__, 'source variable has id ' . $varID_src, 0);
        }
        if ($do) {
            if (AC_GetLoggingStatus($archivID, $varID_src) == false) {
                $s = 'source variable isn\'t logged';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
        }

        if ($do) {
            $ident_dst = self::$ident_var_pfx . $destination;
            @$varID_dst = $this->GetIDForIdent($ident_dst);
            if ($varID_dst == false) {
                $s = 'missing destination variable "' . $ident_dst . '"';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
            $this->SendDebug(__FUNCTION__, 'destination variable "' . $ident_dst . '" has id ' . $varID_dst, 0);
        }
        if ($do) {
            $this->SendDebug(__FUNCTION__, 'clear value from destination variable "' . $ident_dst . '"', 0);
            $this->SetValue($ident_dst, 0);

            $this->SendDebug(__FUNCTION__, 'delete all archive values from destination variable "' . $ident_dst . '"', 0);
            $old_num = AC_DeleteVariableData($archivID, $varID_dst, 0, time());
            $msg .= 'deleted all (' . $old_num . ') from destination variable "' . $ident_dst . '"' . PHP_EOL;

            $dst_vals = [];
            for ($start = $start_ts; $start < $end_ts; $start = $end + 1) {
                $end = $start + (24 * 60 * 60 * 30) - 1;

                $src_vals = AC_GetLoggedValues($archivID, $varID_src, $start, $end, 0);
                foreach ($src_vals as $val) {
                    $dst_vals[] = [
                        'TimeStamp' => $val['TimeStamp'],
                        'Value'     => $val['Value'],
                    ];
                }
                $this->SendDebug(__FUNCTION__, 'start=' . date('d.m.Y H:i:s', $start) . ', end=' . date('d.m.Y H:i:s', $end) . ', count=' . count($src_vals), 0);
            }
            $dst_num = count($dst_vals);
            if ($dst_num > 0) {
                usort($dst_vals, [__CLASS__, 'cmp_val']);
                $start_value = $dst_vals[0]['Value'];
                $start_updated = $dst_vals[0]['TimeStamp'];
                for ($i = 0; $i < $dst_num; $i++) {
                    $dst_vals[$i]['Value'] -= $start_value;
                }
                if ($end_ts == $now) {
                    $end_value = GetValue($varID_src);
                } else {
                    $v = array_pop($dst_vals);
                    $end_value = $v['Value'];
                }
            } else {
                $start_value = 0;
                $start_updated = 0;
                $end_value = GetValue($varID_src);
            }
            $this->SendDebug(__FUNCTION__, 'add ' . $dst_num . ' values, start_val=' . $start_value, 0);
            if (AC_AddLoggedValues($archivID, $varID_dst, $dst_vals) == false) {
                $s = 'add ' . $dst_num . ' values to destination variable "' . $ident_dst . '" failed';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }

            $e = [
                'src_value' => $start_value,
                'updated'   => $start_updated
            ];

            $aggregationType = $this->ReadAttributeInteger('aggregationType');
            if ($aggregationType == 1 /* Zähler */) {
                $variableType = $this->ReadAttributeInteger('variableType');
                switch ($variableType) {
                    case VARIABLETYPE_INTEGER:
                        $e['offset'] = (int) $start_value;
                        break;
                    case VARIABLETYPE_FLOAT:
                        $e['offset'] = (float) $start_value;
                        break;
                }
            }

            $variableData[$destination] = $e;
            $this->SendDebug(__FUNCTION__, 'new offset=' . print_r($variableData, true), 0);
            $this->WriteAttributeString('variableData', json_encode($variableData));
        }

        if ($do) {
            $msg .= 'added ' . $dst_num . ' values to destination variable "' . $ident_dst . '"' . PHP_EOL;

            $this->SendDebug(__FUNCTION__, 're-aggregate variable', 0);
            if (AC_ReAggregateVariable($archivID, $varID_dst) == false) {
                $s = 're-aggregate destination variable "' . $ident_dst . '" failed';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
        }

        if ($do) {
            $this->SendDebug(__FUNCTION__, 'set value from destination variable "' . $ident_dst . '"', 0);
            $this->SetValue($ident_dst, $end_value);

            $msg .= 'destination variable "' . $ident_dst . '" re-aggregated' . PHP_EOL;
        }

        $this->PopupMessage($msg);
    }

    private function findVariables($objID, &$objList)
    {
        $chldIDs = IPS_GetChildrenIDs($objID);
        foreach ($chldIDs as $chldID) {
            $obj = IPS_GetObject($chldID);
            switch ($obj['ObjectType']) {
                case OBJECTTYPE_VARIABLE:
                    if (preg_match('#^' . self::$ident_var_pfx . '#', $obj['ObjectIdent'], $r)) {
                        $objList[] = $obj;
                    }
                    if (preg_match('#^' . self::$ident_sub_pfx . '#', $obj['ObjectIdent'], $r)) {
                        $objList[] = $obj;
                    }
                    break;
                case OBJECTTYPE_CATEGORY:
                    $this->findVariables($chldID, $objList);
                    break;
                default:
                    break;
            }
        }
    }

    private function ChangeIdent($args)
    {
        $this->SendDebug(__FUNCTION__, 'args=' . $args, 0);
        $jargs = json_decode($args, true);

        $destination = $jargs['destination'];
        $new_ident = $jargs['new_ident'];

        $msg = '';

        $do = true;

        if ($do) {
            $ident = self::$ident_var_pfx . $destination;
            @$varID = $this->GetIDForIdent($ident);
            if ($varID == false) {
                $s = 'missing destination variable "' . $ident . '"';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
        }

        if ($do) {
            $this->SendDebug(__FUNCTION__, 'destination variable "' . $ident . '" has id ' . $varID, 0);

            $destinations = json_decode($this->ReadPropertyString('destinations'), true);
            if ($destinations != false) {
                for ($i = 0; $i < count($destinations); $i++) {
                    if ($destinations[$i]['ident'] == $new_ident) {
                        $s = 'new ident is already assigned';
                        $this->SendDebug(__FUNCTION__, $s, 0);
                        $msg .= $s;
                        $do = false;
                    }
                }
            }
        }

        if ($do) {
            if ($destinations != false) {
                for ($i = 0; $i < count($destinations); $i++) {
                    if ($destinations[$i]['ident'] == $destination) {
                        $destinations[$i]['ident'] = $new_ident;
                        break;
                    }
                }

                $this->SendDebug(__FUNCTION__, 'new destinations=' . print_r($destinations, true), 0);

                IPS_SetIdent($varID, self::$ident_var_pfx . $new_ident);
                IPS_SetProperty($this->InstanceID, 'destinations', json_encode($destinations));
                IPS_ApplyChanges($this->InstanceID);
                if ($this->GetValue('Destination') == $destination) {
                    $this->SetValue('Destination', $new_ident);
                }

                $s = 'changed ident to "VAR_' . $new_ident . '"';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
            }
        }

        $this->PopupMessage($msg);
    }

    public function SubtotalInitialize()
    {
        $destination = $this->GetValue('Destination');
        if ($destination != '' && $destination != self::$null_destination) {
            $variableType = $this->ReadAttributeInteger('variableType');
            switch ($variableType) {
                case VARIABLETYPE_INTEGER:
                case VARIABLETYPE_FLOAT:
                    $variableData = json_decode($this->ReadAttributeString('variableData'), true);
                    if (isset($variableData[$destination]) == false) {
                        $this->ChangeDestination($destination);
                        $variableData = json_decode($this->ReadAttributeString('variableData'), true);
                    }
                    $ident = self::$ident_var_pfx . $destination;
                    $cur_value = $this->GetValue($ident);
                    $variableData[$destination]['subtotal'] = $cur_value;
                    $this->WriteAttributeString('variableData', json_encode($variableData));

                    $this->SendDebug(__FUNCTION__, 'initialize subtotal=' . $cur_value, 0);
                    break;
                default:
                    $this->SendDebug(__FUNCTION__, 'only supported with numeric variabletypes', 0);
                    break;
            }
        }
    }

    public function SubtotalBuild()
    {
        $destination = $this->GetValue('Destination');
        if ($destination != '' && $destination != self::$null_destination) {
            $variableType = $this->ReadAttributeInteger('variableType');
            switch ($variableType) {
                case VARIABLETYPE_INTEGER:
                case VARIABLETYPE_FLOAT:
                    $variableData = json_decode($this->ReadAttributeString('variableData'), true);
                    if (isset($variableData[$destination]) == false) {
                        $this->ChangeDestination($destination);
                        $variableData = json_decode($this->ReadAttributeString('variableData'), true);
                    }
                    if (isset($variableData[$destination]['subtotal'])) {
                        $sub_value = $variableData[$destination]['subtotal'];
                    } else {
                        $sub_value = 0;
                    }
                    $ident = self::$ident_var_pfx . $destination;
                    $cur_value = $this->GetValue($ident);
                    $variableData[$destination]['subtotal'] = $cur_value;
                    $this->WriteAttributeString('variableData', json_encode($variableData));

                    $ident = self::$ident_sub_pfx . $destination;
                    $new_value = $this->GetValue($ident) + ($cur_value - $sub_value);
                    $this->SetValue($ident, $new_value);

                    $this->SendDebug(__FUNCTION__, 'subtotal=' . $sub_value . '/' . $cur_value . ', new_value=' . $new_value, 0);
                    break;
                default:
                    $this->SendDebug(__FUNCTION__, 'only supported with numeric variabletypes', 0);
                    break;
            }
        }
    }
}
