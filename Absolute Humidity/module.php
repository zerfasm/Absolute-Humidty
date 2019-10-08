<?php

require_once __DIR__.'/../libs/traits.php';  // Allgemeine Funktionen

// CLASS Absolute Humidity
class Absolute Humidity extends IPSModule
{
    use ProfileHelper, DebugHelper;

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        // Outdoor variables
        $this->RegisterPropertyInteger('TempOutdoor', 0);
        $this->RegisterPropertyInteger('HumyOutdoor', 0);

        $this->RegisterPropertyInteger('UpdateTimer', 15);
        $this->RegisterPropertyBoolean('CreateDewPoint', true);
        $this->RegisterPropertyBoolean('CreateWaterContent', true);
        // Update trigger
        $this->RegisterTimer('UpdateTrigger', 0, "AHC_Update(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        // Update Trigger Timer
        $this->SetTimerInterval('UpdateTrigger', 1000 * 60 * $this->ReadPropertyInteger('UpdateTimer'));

        // Profile "AHC.AirOrNot"
        $association = [
            [0, 'Nicht Lüften!', 'Window-100', 0x00FF00],
            [1, 'Lüften!', 'Window-0', 0xFF0000],
        ];
        $this->RegisterProfile(vtBoolean, 'AHC.AirOrNot', 'Window', '', '', 0, 0, 0, 0, $association);

        // Profile "AHC.WaterContent"
        $association = [
            [0, '%0.2f', '', 0x808080],
        ];
        $this->RegisterProfile(vtFloat, 'AHC.WaterContent', 'Drops', '', ' g/m³', 0, 0, 0, 0, $association);

        // Profile "AHC.Difference"
        $association = [
            [-500, '%0.2f %%', 'Window-0', 16711680],
            [0, '%0.2f %%', 'Window-0', 16711680],
            [0.01, '+%0.2f %%', 'Window-100', 16744448],
            [10, '+%0.2f %%', 'Window-100', 32768],
        ];
        $this->RegisterProfile(vtFloat, 'AHC.Difference', 'Window', '', '', 0, 0, 0, 2, $association);

        // Ergebnis & Hinweis & Differenz
        $this->MaintainVariable('Hint', 'Hinweis', vtBoolean, 'AHC.AirOrNot', 1, true);
        $this->MaintainVariable('Result', 'Ergebnis', vtString, '', 2, true);
        $this->MaintainVariable('Difference', 'Differenz', vtFloat, 'AHC.Difference', 3, true);
        
		// Taupunkt
        $create = $this->ReadPropertyBoolean('CreateDewPoint');
        $this->MaintainVariable('DewPointOutdoor', 'Taupunkt Aussen', vtFloat, '~Temperature', 4, $create);

        // Wassergehalt (WaterContent)
        $create = $this->ReadPropertyBoolean('CreateWaterContent');
        $this->MaintainVariable('WaterContentOutdoor', 'Wassergehalt Aussen', vtFloat, 'AHC.WaterContent', 6, $create);
    }

    /**
     * This function will be available automatically after the module is imported with the module control.
     * Using the custom prefix this function will be callable from PHP and JSON-RPC through:.
     *
     * THS_Update($id);
     */
    public function Update()
    {
        $result = 'Ergebnis konnte nicht ermittelt werden!';
        // Daten lesen
        $state = true;
        // Temp Outdoor
        $to = $this->ReadPropertyInteger('TempOutdoor');
        if ($to != 0) {
            $to = GetValue($to);
        } else {
            $this->SendDebug('UPDATE', 'Temperature Outdoor not set!');
            $state = false;
        }
        // Humidity Outdoor
        $ho = $this->ReadPropertyInteger('HumyOutdoor');
        if ($ho != 0) {
            $ho = GetValue($ho);
            // Kann man bestimmt besser lösen
            if ($ho < 1) {
                $ho = $ho * 100.;
            }
        } else {
            $this->SendDebug('UPDATE', 'Humidity Outdoor not set!');
            $state = false;
        }

        // All okay
        if ($state == false) {
            $this->SetValueString('Result', $result);

            return;
        }

        // Minus oder Plus ;-)
        if ($ti >= 0) {
            // Plustemperaturen
            $ao = 7.5;
            $bo = 237.7;
            $ai = $ao;
            $bi = $bo;
        } else {
            // Minustemperaturen
            $ao = 7.6;
            $bo = 240.7;
            $ai = $ao;
            $bi = $bo;
        }

        // universelle Gaskonstante in J/(kmol*K)
        $rg = 8314.3;
        // Molekulargewicht des Wasserdampfes in kg
        $m = 18.016;
        // Umrechnung in Kelvin
        $ko = $to + 273.15;
        $ki = $ti + 273.15;
        // Berechnung Sättigung Dampfdruck in hPa
        $so = 6.1078 * pow(10, (($ao * $to) / ($bo + $to)));
        $si = 6.1078 * pow(10, (($ai * $ti) / ($bi + $ti)));
        // Dampfdruck in hPa
        $do = ($ho / 100) * $so;
        $di = ($hi / 100) * $si;
        // Berechnung Taupunkt Aussen
        $vo = log10($do / 6.1078);
        $dpo = $bo * $vo / ($ao - $vo);
        // Speichern Taupunkt?
        $update = $this->ReadPropertyBoolean('CreateDewPoint');
        if ($update == true) {
            $this->SetValue('DewPointOutdoor', $dpo);
        }
        // WaterContent
        $wco = pow(10, 5) * $m / $rg * $do / $ko;
        // Speichern Wassergehalt?
        $update = $this->ReadPropertyBoolean('CreateWaterContent');
        if ($update == true) {
            $this->SetValue('WaterContentOutdoor', $wco);
        }
    }

    /**
     * This function will be available automatically after the module is imported with the module control.
     * Using the custom prefix this function will be callable from PHP and JSON-RPC through:.
     *
     * TSH_Duration($id, $duration);
     *
     * @param int $duration Wartezeit einstellen.
     */
    public function Duration(int $duration)
    {
        IPS_SetProperty($this->InstanceID, 'UpdateTimer', $duration);
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * This function will be available automatically after the module is imported with the module control.
     * Using the custom prefix this function will be callable from PHP and JSON-RPC through:.
     *
     * TSH_SetMessageThreshold($id, $threshold);
     *
     * @param int MessageThreshold Schwellert einstellen.
     */
    public function MessageThreshold(int $threshold)
    {
        IPS_SetProperty($this->InstanceID, 'MessageThreshold', $threshold);
        IPS_ApplyChanges($this->InstanceID);
    }
}
