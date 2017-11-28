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
     * The local resource, based on the Hexon Id
     * @var Occasion
     */
    protected $resource;

    /**
     * Maps attributes from the export to model attributes
     * @var Array
     */
    protected $occasionAttributeMap = [
    ];

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
                    return;
                }

                // Get the existing resource or create it with the resourceId
                $this->resource = Occasion::where('hexon_id', $this->resourceId)->firstOrCreate([
                    'resource_id' => $this->resourceId
                ]);

                // The current version of the resource
                $this->setAttribute('version', $xml->attributes()->versie);

                // Set all attributes and special properties of the resource
                $this->setAttribute('brand', $xml->merk);
                $this->setAttribute('model', $xml->model);
                $this->setAttribute('type', $xml->type);
                $this->setAttribute('build_year', $xml->bouwjaar);
                $this->setAttribute('license_plate', $xml->kenteken);

                $this->setAttribute('bodywork', $xml->carrosserie);
                $this->setAttribute('color', $xml->kleur);
                $this->setAttribute('base_color', $xml->basiskleur);
                $this->setAttribute('lacquer', $xml->laktint);
                $this->setAttribute('lacquer_type', $xml->laksoort);
                $this->setAttribute('num_doors', $xml->aantal_deuren, 'int');
                $this->setAttribute('num_seats', $xml->aantal_zitplaatsen, 'int');

                $this->setAttribute('fuel_type', $xml->brandstof);
                $this->setAttribute('mileage', $xml->tellerstand, 'int');
                $this->setAttribute('mileage_unit', $xml->tellerstand_eenheid);
                $this->setAttribute('range', $xml->actieradius, 'int');

                $this->setAttribute('transmission', $xml->transmissie);
                $this->setAttribute('num_gears', $xml->aantal_versnellingen, 'int');

                $this->setAttribute('mass', $xml->cilinder_aantal, 'int');
                $this->setAttribute('max_towing_weight', $xml->max_trekgewicht, 'int');
                $this->setAttribute('num_cylinders', $xml->cilinder_aantal, 'int');
                $this->setAttribute('cylinder_capacity', $xml->cilinder_inhoud, 'int');

                $this->setAttribute('power', $xml->vermogen_motor, 'int');
                // todo: vermogen_motor_kw
                // todo: vermogen_motor_pk
                $this->setAttribute('power_type', $xml->vermogensoort);
                $this->setAttribute('top_speed', $xml->topsnelheid);

                $this->setAttribute('fuel_capacity', $xml->tankinhoud, 'int');
                $this->setAttribute('fuel_consumption_avg', $xml->gemiddeld_verbruik, 'float');
                $this->setAttribute('fuel_consumption_city', $xml->verbruik_stad, 'float');
                $this->setAttribute('fuel_consumption_highway', $xml->verbruik_snelweg, 'float');
                $this->setAttribute('co2_emission', $xml->co2_uitstoot);
                $this->setAttribute('energy_label', $xml->energie_label);

                $this->setAttribute('vat_margin', $xml->btw_marge);
                $this->setAttribute('vehicle_tax', $xml->bpm_bedrag, 'int');
                $this->setAttribute('delivery_costs', $xml->kosten_rijklaar, 'int');

                // todo: which one to use? 
                // - verkoopprijs_particulier
                // - verkoopprijs_handel
                // - actieprijs
                // - exportprijs
                // - meeneemprijs
                $this->setAttribute('price', $xml->verkoopprijs_particulier, 'int');

                $this->setAttribute('sold', (string) $xml->verkocht === 'j', 'boolean');
                $this->setAttribute('sold_at', $xml->verkocht_datum, 'date');

                // Sets the accessories
                // todo: how to handle accessory groups?
                $this->setAccessories($xml->accessoires);

                // Stores all images
                $this->storeImages($xml->afbeeldingen);

                // Try to save the resource
                try {
                    $this->resource->save();

                // If saving failed, we delete the newly created resource
                } catch(\Exception $e) {
                    $this->resource->delete();

                    // $this->setError($e->getMessage());
                    $this->setError('Unable to save or update resource.');
                }

                break;

            // Deletes the resource and all associated data
            case 'delete':

                $this->resource = Occasion::where('hexon_id', $this->resourceId)->first();

                if(! $this->resource)
                {
                    $this->setError('Error deleting resource. Resource could not be found.');
                    return;
                }

                $this->resource->delete();
                break;

            // Nothing to do here...
            default:
                break;
        }

        // Store the XML to disk
        $this->saveXml($xml);
    }

    /**
     * Sets an attribute to the resource and casts to desired type
     * @param string $attr  The attribut key to set
     * @param mixed  $value The value
     * @param string $type  To which type to cast
     */
    private function setAttribute($attr, $value, $type = 'string', $fallback = null)
    {
        switch ($type) {
            case 'int':
                $value = (int) $value;
                break;

            case 'string':
                $value = (string) $value;
                break;

            case 'boolean':
                $value = (bool) $value;
                break

            // Try to parse as a Carbon object, if it fails set it to the fallback value
            case 'date':
                try {
                    $value = Carbon::parse($value);

                } catch(\Exception $e)
                {
                    $value = $fallback;
                }

                break;
        }

        if( empty($value) )
        {
            $value = $fallback;
        }

        $this->resource->setAttribute($attr, $value);
    }

    /**
     * Sets the accessories
     *
     * @param array $accessories
     * @return void
     */
    private function setAccessories($accessories)
    {
        // First, remove all accessories
        $this->resource->accessoires->delete();

        foreach ($accessories as $accessory)
        {
            $this->resource->accessories->create([
                'name' => (string) $accessory
            ]);
        }
    }

    /**
     * Stores the images to disk
     * @param  Array $images An array of images
     * @return void
     */
    private function storeImages($images)
    {
        // todo: do we need to delete all images before storing?
        // this could be very slow
        // $this->resource->images->delete();

        foreach ($images as $imageId => $imageUrl)
        {
            if( $contents = file_get_contents($imageUrl) )
            {
                $filename = implode('_', [
                    $this->resourceId,
                    $imageId
                ]).'jpg';

                $imageResource = $this->resource->images->create([
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
     * Stores the XML to disk
     * @param  SimpleXmlElement $xml The XML data to write to disk
     * @return void
     */
    private function saveXml($xml)
    {
        $filename = implode('_', [
            Carbon::format('Y-m-dH:i:s'),
            $this->resourceId
        ]).'xml';

        Storage::put(config('hexon-export.xml_storage_path') . $filename, $xml);
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

    /**
     * Set an error
     * @param string $err The error description
     */
    public function setError($err)
    {
        array_push($this->errors, $err);
    }
}