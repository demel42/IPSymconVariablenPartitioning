<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class VariableSplit extends IPSModule
{
    use VariableSplit\StubsCommonLib;
    use VariableSplitLocalLib;

    private static $semaphoreID = __CLASS__;
    private static $semaphoreTM = 5 * 1000;

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

        $this->RegisterAttributeInteger('aggregationType', 0);

        $this->InstallVarProfiles(false);

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
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
                'Wert'  => '-',
                'Name'  => 'no selection',
                'Farbe' => -1
            ],
        ];
        foreach ($destinations as $destination) {
            $associations[] = [
                'Wert'  => $destination['ident'],
                'Name'  => $destination['name'],
                'Farbe' => -1
            ];
        }
        $this->SendDebug(__FUNCTION__, 'associations=' . print_r($associations, true), 0);
        $varProf = 'VariableSplit_' . $this->InstanceID . '.Destinations';
        $this->CreateVarProfile($varProf, VARIABLETYPE_STRING, '', 0, 0, 0, 0, '', $associations, true);

        $vpos = 1;
        $this->MaintainVariable('Destination', $this->Translate('Destination'), VARIABLETYPE_STRING, $varProf, $vpos++, true);
        $this->MaintainAction('Destination', true);

        $vpos = 10;

        $source_varID = $this->ReadPropertyInteger('source_varID');
        if (IPS_VariableExists($source_varID)) {
            $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];

            $var = IPS_GetVariable($source_varID);
            $varType = $var['VariableType'];
            $varProf = $var['VariableProfile'];
            $varCustomProf = $var['VariableCustomProfile'];
            $hasLogging = AC_GetLoggingStatus($archivID, $source_varID);
            $aggregationType = AC_GetAggregationType($archivID, $source_varID);
            $ignoreZero = AC_GetCounterIgnoreZeros($archivID, $source_varID);

            $this->WriteAttributeInteger('aggregationType', $aggregationType);

            $varList = [];
            foreach ($destinations as $destination) {
                $ident = 'VAR_' . $destination['ident'];
                $name = $destination['name'];
                $this->MaintainVariable($ident, $name, $varType, $varProf, $vpos++, true);
                $varList[] = $ident;

                $varID = $this->GetIDForIdent($ident);

                IPS_SetName($varID, $name);
                IPS_SetVariableCustomProfile($varID, $varCustomProf);

                $reAggregate = AC_GetLoggingStatus($archivID, $source_varID) == false || AC_GetAggregationType($archivID, $source_varID) != $aggregationType;
                if ($hasLogging) {
                    AC_SetLoggingStatus($archivID, $varID, true);
                    AC_SetAggregationType($archivID, $varID, $aggregationType);
                    AC_SetCounterIgnoreZeros($archivID, $varID, $ignoreZero);
                    if ($reAggregate) {
                        AC_ReAggregateVariable(archivID, $varID);
                    }
                } else {
                    AC_SetLoggingStatus($archivID, $varID, false);
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
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Variable split');

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

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
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
                $r = true;
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
            $ident = $this->GetValue('Destination');
            if ($ident != '' && $ident != '-') {
                $ident = 'VAR_' . $ident;
                $aggregationType = $this->ReadAttributeInteger('aggregationType');
                if ($aggregationType == 1 /* Zähler */) {
                    $diff = $value - $oldValue;
                    $val = $this->GetValue($ident) + $diff;
                    $this->SetValue($ident, $val);
                    $this->SendDebug(__FUNCTION__, 'change variable "' . $ident . '", increment value by ' . $diff . ' to ' . $val, 0);
                } else {
                    $this->SetValue($ident, $value);
                    $this->SendDebug(__FUNCTION__, 'change variable "' . $ident . '", set value to ' . $value, 0);
                }
            } else {
                $this->SendDebug(__FUNCTION__, 'no destination selected', 0);
            }
        }
    }

    private function findVariables($objID, &$objList)
    {
        $chldIDs = IPS_GetChildrenIDs($objID);
        foreach ($chldIDs as $chldID) {
            $obj = IPS_GetObject($chldID);
            switch ($obj['ObjectType']) {
                case OBJECTTYPE_VARIABLE:
                    if (preg_match('#^VAR_#', $obj['ObjectIdent'], $r)) {
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
}
