<?php

namespace App\Domain\Delivery\Enums;

/**
 * What the candidate's browser reported. Severity is assigned by type, not chosen at the point of
 * recording, so the same event always weighs the same (ProctorSeverity::for()).
 */
enum ProctorEventType: string
{
    case FullscreenExited = 'fullscreen_exited';
    case TabHidden = 'tab_hidden';
    case CopyAttempt = 'copy_attempt';
    case PasteAttempt = 'paste_attempt';
    case RightClick = 'right_click';
    case PrintAttempt = 'print_attempt';
    case DevtoolsOpened = 'devtools_opened';
    case UnknownDevice = 'unknown_device';

    public function label(): string
    {
        return match ($this) {
            self::FullscreenExited => 'Left full screen',
            self::TabHidden => 'Switched away from the exam tab',
            self::CopyAttempt => 'Attempted to copy',
            self::PasteAttempt => 'Attempted to paste',
            self::RightClick => 'Right-clicked',
            self::PrintAttempt => 'Attempted to print',
            self::DevtoolsOpened => 'Opened developer tools',
            self::UnknownDevice => 'Signed in from a device not yet approved for this centre',
        };
    }

    public function severity(): ProctorSeverity
    {
        return match ($this) {
            self::DevtoolsOpened, self::PrintAttempt, self::UnknownDevice => ProctorSeverity::High,
            self::FullscreenExited, self::TabHidden => ProctorSeverity::Medium,
            self::CopyAttempt, self::PasteAttempt, self::RightClick => ProctorSeverity::Low,
        };
    }
}
