<?php

namespace App\Observers;

use App\Support\ActivityLog\ActivityPropertiesNormalizer;
use Spatie\Activitylog\Models\Activity;

class ActivityLogObserver
{
    /**
     * @param Activity $activity
     * @return void
     */
    public function creating(Activity $activity): void
    {
        $properties = ActivityPropertiesNormalizer::toArray($activity->properties);
        $activity->properties = ActivityPropertiesNormalizer::compress($properties, $activity->event);

        if (ActivityPropertiesNormalizer::isDerivableDescription($activity)) {
            $activity->description = '';
        }
    }
}
