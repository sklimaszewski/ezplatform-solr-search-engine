<?php
/**
 * File containing the Solr\Slot\HideLocation class
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version //autogentag//
 */

namespace eZ\Publish\Core\Search\Solr\Slot;

use eZ\Publish\Core\SignalSlot\Signal;
use eZ\Publish\Core\Search\Solr\Slot;

/**
 * A Solr slot handling HideLocationSignal.
 */
class HideLocation extends Slot
{
    /**
     * Receive the given $signal and react on it
     *
     * @param \eZ\Publish\Core\SignalSlot\Signal $signal
     */
    public function receive( Signal $signal )
    {
        if ( !$signal instanceof Signal\LocationService\HideLocationSignal )
        {
            return;
        }

        $this->searchHandler->contentSearchHandler()->indexContent(
            $this->persistenceHandler->contentHandler()->load( $signal->contentId, $signal->currentVersionNo )
        );

        $this->searchHandler->locationSearchHandler()->indexLocation(
            $this->persistenceHandler->locationHandler()->load( $signal->locationId )
        );
    }
}
