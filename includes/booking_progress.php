<?php

function booking_progress_state(array $booking): array
{
    $stage = $booking['workflow_stage'] ?? null;
    if (!$stage) {
        $stage = match ($booking['status'] ?? 'Pending') {
            'Approved' => 'AdminApproved',
            'Completed' => 'Completed',
            'Rejected' => 'AdminRejected',
            'Cancelled' => 'Cancelled',
            default => 'Submitted',
        };
    }

    $step = match ($stage) {
        'DriverAssigned', 'ReassignmentRequired' => 2,
        'DriverAccepted' => 3,
        'AdminApproved' => 4,
        'Completed' => 5,
        default => 1,
    };

    return [
        'stage' => $stage,
        'step' => $step,
        'reassignment_required' => $stage === 'ReassignmentRequired',
    ];
}

function render_booking_progress(array $booking): void
{
    $state = booking_progress_state($booking);
    $steps = [
        1 => 'Tempahan Dihantar',
        2 => 'Pemandu Ditugaskan',
        3 => 'Pemandu Menerima Tugasan',
        4 => 'Diluluskan',
        5 => 'Selesai',
    ];

    $stepClasses = static function (int $number) use ($state): string {
        if ($state['reassignment_required'] && $number === 2) {
            return 'step-error';
        }
        if ($state['stage'] === 'Completed' && $number <= $state['step']) {
            return 'step-success';
        }
        if ($number < $state['step']) {
            return 'step-success';
        }
        if ($number === $state['step']) {
            return 'step-success';
        }
        return '';
    };

    ?>
    <section class="card p-5 sm:p-6 mb-5" aria-labelledby="booking-progress-title">
        <div class="flex items-start justify-between gap-3 mb-4">
            <div>
                <h6 id="booking-progress-title" class="font-semibold">Progress Tempahan</h6>
                <p class="text-xs" style="color:var(--ta-muted)">Langkah <?= (int)$state['step'] ?> daripada 5</p>
            </div>
            <?php if ($state['reassignment_required']): ?>
                <span class="badge badge-error badge-sm">Perlu tugasan semula</span>
            <?php endif; ?>
        </div>

        <ul class="steps steps-vertical sm:steps-horizontal w-full">
            <?php foreach ($steps as $number => $label): ?>
                <li class="step <?= $stepClasses($number) ?>" data-content="<?= $number ?>">
                    <span class="text-xs sm:text-sm font-medium <?= $number === $state['step'] ? 'text-base-content' : '' ?>">
                        <?= htmlspecialchars($label) ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($state['reassignment_required']): ?>
            <div class="alert alert-error mt-4 py-3 text-sm">
                Pemandu menolak tugasan ini. Admin perlu menetapkan pemandu baharu sebelum tempahan boleh diteruskan.
            </div>
        <?php endif; ?>
    </section>
    <?php
}
