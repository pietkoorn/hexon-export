<?php

namespace RoyScheepens\HexonExport;

use RoyScheepens\HexonExport\Models\Occasion;
use RoyScheepens\HexonExport\Models\OccasionImage;
use RoyScheepens\HexonExport\Models\OccasionAccessory;

use Storage;

use Illuminate\Support\Str;
use Carbon\Carbon;

class HexonExport {

    /**
     * The Hexon Id of the resource
     * @var Number
     */
    protected $resourceId;

    /**
     * The local resource we are going to create or update
     * @var Occasion
     */
    protected $resource;

    /**
     * Array of errors
     * @var array
     */
    protected $errors = [];

    /**
     * Class Constructor
     */
    function __construct()
    {
        // todo: add option to set image disk on storage
    }

    /**
     * Handles the import of the XML
     *
     * @param \SimpleXmlElement $xml
     * @return void
     */
    public function handle(\SimpleXmlElement $xml)
    {
        // The resource id from Hexon
        $this->resourceId = (int) $xml->voertuignr_hexon;

        // Store the XML to disk before processing
        $this->saveXml($xml);

        // Perform an insert/update or delete, based on the action supplied
        switch ($xml->attributes()->actie)
        {
            // Inserts or updates the existing record
            case 'add':
            case 'change':

                // Check if the resource has any images
                if(empty($xml->afbeeldingen))
                {
                    $this->setError('No images supplied, cannot proceed.');
                    return $this;
                }

                // todo: set version check (2.12)
                // $xml->attributes()->versie

                try {

                    // Get the existing resource or create it with the resourceId
                    $this->resource = Occasion::where('resource_id', $this->resourceId)->firstOrNew([
                        'resource_id' => $this->resourceId
                    ]);

                    // Set all attributes and special properties of the resource
                    $this->setAttribute('brand', $xml->merk);
                    $this->setAttribute('model', $xml->model);
                    $this->setAttribute('type', $xml->type);
                    $this->setAttribute('license_plate', $xml->kenteken);
                    $this->setAttribute('apk_until', $xml->apk->attributes()->tot, 'date');

                    $this->setAttribute('bodywork', $xml->carrosserie);
                    $this->setAttribute('color', $xml->kleur);
                    $this->setAttribute('base_color', $xml->basiskleur);
                    $this->setAttribute('lacquer', $xml->laktint);
                    $this->setAttribute('lacquer_type', $xml->laksoort);
                    $this->setAttribute('num_doors', $xml->aantal_deuren, 'int');
                    $this->setAttribute('num_seats', $xml->aantal_zitplaatsen, 'int');

                    $this->setAttribute('fuel_type', $xml->brandstof);
                    $this->setAttribute('mileage', $xml->tellerstand, 'int');
                    $this->setAttribute('mileage_unit', $xml->tellerstand->attributes()->eenheid);
                    $this->setAttribute('range', $xml->actieradius, 'int');

                    $this->setAttribute('transmission', $xml->transmissie);
                    $this->setAttribute('num_gears', $xml->aantal_versnellingen, 'int');

                    $this->setAttribute('mass', $xml->massa, 'int');
                    $this->setAttribute('max_towing_weight', $xml->max_trekgewicht, 'int');
                    $this->setAttribute('payload', $xml->laadvermogen, 'int');
                    $this->setAttribute('num_cylinders', $xml->cilinder_aantal, 'int');
                    $this->setAttribute('cylinder_capacity', $xml->cilinder_inhoud, 'int');

                    $this->setAttribute('power_hp', $xml->vermogen_motor_pk, 'int');
                    $this->setAttribute('power_kw', $xml->vermogen_motor_kw, 'int');

                    $this->setAttribute('top_speed', $xml->topsnelheid);

                    $this->setAttribute('fuel_capacity', $xml->tankinhoud, 'int');
                    $this->setAttribute('fuel_consumption_avg', $xml->gemiddeld_verbruik, 'float');
                    $this->setAttribute('fuel_consumption_city', $xml->verbruik_stad, 'float');
                    $this->setAttribute('fuel_consumption_highway', $xml->verbruik_snelweg, 'float');
                    $this->setAttribute('co2_emission', $xml->co2_uitstoot);
                    $this->setAttribute('energy_label', $xml->energie_label);

                    $this->setAttribute('remarks', $xml->opmerkingen);

                    $this->setAttribute('vat_margin', $xml->btw_marge);
                    $this->setAttribute('vehicle_tax', $xml->bpm_bedrag, 'int');
                    $this->setAttribute('road_tax_min', $xml->wegenbelasting_kwartaal->attributes()->min, 'int');
                    $this->setAttribute('road_tax_max', $xml->wegenbelasting_kwartaal->attributes()->max, 'int');
                    $this->setAttribute('delivery_costs', $xml->kosten_rijklaar, 'int');

                    $this->setAttribute('length', $xml->lengte, 'int');
                    $this->setAttribute('width', $xml->breedte, 'int');
                    $this->setAttribute('height', $xml->hoogte, 'int');
                    $this->setAttribute('wheel_base', $xml->wielbasis, 'int');
                    $this->setAttribute('nap_weblabel', $xml->nap_weblabel);
                    $this->setAttribute('cabine_lengte', $xml->cabine_lengte);

                    $this->setAttribute('price', $xml->verkoopprijs_particulier, 'int');

                    $isSold = (string) $xml->verkocht === 'j' || (int) $xml->verkoopprijs_particulier < 10;

                    $this->setAttribute('sold', (string) $xml->verkocht === 'j', 'boolean');
                    $this->setAttribute('sold_at', $xml->verkocht_datum, 'date');

                    // Sets the build year
                    $this->setBuildYear($xml->datum_deel_1);

                    // Save the resource to the database, so we can start
                    // adding relations
                    $this->resource->save();

                    // Sets the accessories
                    $this->setAccessories($xml->accessoires->accessoire);

                    // Sets the accessorygroups
                    $this->setAccessorygroups($xml->accessoiregroepen);

                    // Set the images
                    $this->setImages($xml->afbeeldingen->afbeelding);

                    // Set the videos
                    $this->setVideos($xml->videos);                    

                } catch(\Exception $e) {

                    $this->setError('Unable to save or update resource.');

                    $this->setError($e->getMessage());
                }

                break;

            // Deletes the resource and all associated data
            case 'delete':

                $this->resource = Occasion::where('resource_id', $this->resourceId)->first();

                if($this->resource)
                {
                    $this->resource->delete();
                }

                break;

            // Nothing to do here...
            default:
                break;
        }

        return $this;
    }

    /**
     * Sets an attribute to the resource and casts to desired type
     * @param string $attr  The attribut key to set
     * @param mixed  $value The value
     * @param string $type  To which type to cast
     */
    protected function setAttribute($attr, $value, $type = 'string', $fallback = null)
    {
        switch ($type) {
            case 'int':
                $value = (int) $value;
                break;

            case 'string':
                $value = (string) $value;
                break;

            case 'boolean':
                $value = $value ? true : false;
                break;

            // Try to parse as a Carbon object, if it fails set it to the fallback value
            case 'date':
                try {
                    $value = Carbon::createFromFormat('d-m-Y', $value);

                } catch(\Exception $e)
                {
                    $value = $fallback;
                }

                break;
        }

        // Use the fallback value should it be empty
        if( $type !== 'boolean' && empty($value) )
        {
            $value = $fallback;
        }

        $this->resource->setAttribute($attr, $value);
    }

    /**
     * Sets the build_year attribute based on the datum_deel_1 value
     *
     * @param string $registrationDate
     * @return void
     */
    private function setBuildYear($registrationDate)
    {
        if($date = Carbon::createFromFormat('d-m-Y', $registrationDate))
        {
            $this->resource->setAttribute('build_year', $date->format('m-Y'));
        }
    }

    /**
     * Sets the accessories
     *
     * @param array $accessories
     * @return void
     */
    protected function setAccessories($accessories)
    {
        // First, remove all accessories
        $this->resource->accessories()->delete();

        foreach ($accessories as $accessory)
        {
            $name = (string) $accessory->naam;

            if(
                empty($name) ||
                strlen($name) <= 1 ||
                in_array(substr($name, 0, 1), ['(', ')', '&'])
            ) {
                continue;
            }

            $this->resource->accessories()->create([
                'name' => Str::limit($name, 160)
            ]);
        }
    }

    /**
     * Sets the accessorygroups
     *
     * @param array $accessorygroups
     * @return void
     */
    protected function setAccessorygroups($accessorygroups)
    {
        // First, remove all accessorygroups
        $this->resource->accessorygroups()->delete();

        foreach($accessorygroups->accessoiregroep as $group) {
            foreach ($group->accessoire as $accessorygroup) {
                $name = (string) $accessorygroup;

                if(
                    empty($name) ||
                    strlen($name) <= 1 ||
                    in_array(substr($name, 0, 1), ['(', ')', '&'])
                ) {
                    continue;
                }

                $this->resource->accessorygroups()->create([
                    'groupname' => $group['naam'],
                    'name' => Str::limit($name, 160)
                ]);
            }
        }
    }

    /**
     * Stores the images to disk
     * @param  Array $images An array of images
     * @return void
     */
    protected function setImages($images)
    {
        $this->resource->images()->delete();

        foreach ($images as $image)
        {
            $imageId = (int) $image->attributes()->nr;
            $imageUrl = (string) $image->url;

            if( $contents = file_get_contents($imageUrl) )
            {
                $filename = implode('_', [
                    $this->resourceId,
                    $imageId
                ]).'.jpg';

                $imageResource = $this->resource->images()->create([
                    'resource_id' => $imageId,
                    'filename' => $filename
                ]);

                // Use the path attribute to set as the file destination
                Storage::disk('public')->put($imageResource->path, $contents);

                $imageResource->save();

            } else {
                // todo: handle exception?
            }
        }
    }

    /**
     * Sets the videos
     * @param  Array $videos An array of videos
     * @return void
     */
    protected function setVideos($videos)
    {
        // First, remove all videos
        $this->resource->videos()->delete();

        if(!empty($videos->video)) {
            foreach($videos->video as $video) {
                $videoId = (int) $video->attributes()->nr;
                $videoSource = (string) $video->bron;
                $videoCode = (string) $video->videocode;

                $this->resource->videos()->create([
                    'resource_id' => $videoId,
                    'source' => $videoSource,
                    'code' => $videoCode
                ]);
            }
        }
    }

    /**
     * Stores the XML to disk
     * @param  SimpleXmlElement $xml The XML data to write to disk
     * @return void
     */
    protected function saveXml($xml)
    {
        $filename = implode(' ', [
            Carbon::now()->toDateTimeString(),
            $this->resourceId
        ]).'.xml';

        Storage::put(config('hexon-export.xml_storage_path') . $filename, $xml->asXML());
    }

    /**
     * Set an error
     * @param string $err The error description
     */
    protected function setError($err)
    {
        array_push($this->errors, $err);
    }

    /**
     * Do we have any errors?
     * @return boolean True if we do, false if not
     */
    public function hasErrors()
    {
        return count($this->errors) <> 0;
    }

    /**
     * Returns the errors
     * @return array Array of errors
     */
    public function getErrors()
    {
        if($this->hasErrors())
        {
            return $this->errors;
        }

        return [];
    }

}