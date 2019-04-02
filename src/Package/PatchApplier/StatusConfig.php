<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Package\PatchApplier;

class StatusConfig
{
    public function getLabels()
    {
        return array(
            'new' => '<info>NEW</info>',
            'match' => '<info>MATCH</info>',
            'changed' => '<fg=yellow>CHANGED</>',
            'applied' => '<fg=white;options=bold>APPLIED</>',
            'removed' => '<fg=red>REMOVED</>',
            'excluded' => '<fg=black>EXCLUDED</>',
            'unknown' => 'UNKNOWN'
        );
    }
}
