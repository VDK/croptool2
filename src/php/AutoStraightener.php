<?php

namespace CropTool;

use pastuhov\Command\Command;

class AutoStraightener
{
    const MAX_ANGLE = 15;

    public function detectAngle($filename)
    {
        $commands = [
            'magick {src} -resize {size} -colorspace Gray -deskew 40% -format "%[deskew:angle]" info:',
            'convert {src} -resize {size} -colorspace Gray -deskew 40% -format "%[deskew:angle]" info:',
        ];

        foreach ($commands as $command) {
            try {
                $output = trim(Command::exec($command, [
                    'src' => $filename,
                    'size' => '1200x1200>',
                ]));
            } catch (\Exception $e) {
                continue;
            }

            if (!is_numeric($output)) {
                continue;
            }

            $angle = round((float)$output, 1);
            if (abs($angle) > self::MAX_ANGLE) {
                return 0.0;
            }
            return $angle;
        }

        throw new \RuntimeException('Could not auto-detect a straighten angle.');
    }
}
