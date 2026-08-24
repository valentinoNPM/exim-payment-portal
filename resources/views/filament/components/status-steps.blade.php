@php
    $record = $getRecord();
    $currentStatus = $record ? $record->status : 'draft';
    
    $steps = [
        'draft' => 'Draft',
        'submitted' => 'Accounting',
        'pending_approval' => 'GM Approval',
        'approved' => 'Approved',
        'exported' => 'Exported',
    ];

    $stepKeys = array_keys($steps);
    $currentIndex = array_search($currentStatus, $stepKeys);
    if ($currentIndex === false) $currentIndex = 0;
@endphp

<style>
    .stepper-container {
        margin-bottom: 1.5rem;
        padding: 1.5rem;
        padding-bottom: 3rem;
        border-radius: 0.75rem;
        background-color: rgb(255 255 255);
        box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        border: 1px solid rgb(9 9 11 / 0.05);
    }
    .dark .stepper-container {
        background-color: rgb(24 24 27);
        border-color: rgb(255 255 255 / 0.1);
    }
    .stepper-list {
        display: flex;
        align-items: center;
        width: 100%;
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .stepper-item {
        display: flex;
        align-items: center;
        flex: 1;
    }
    .stepper-item:last-child {
        flex: 0;
    }
    .stepper-icon-wrapper {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        position: relative;
    }
    .stepper-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        border-radius: 9999px;
        border-width: 2px;
        border-style: solid;
        font-size: 0.875rem;
        font-weight: 600;
        z-index: 10;
    }
    
    .stepper-label {
        position: absolute;
        top: 2.5rem;
        left: 50%;
        transform: translateX(-50%);
        white-space: nowrap;
        font-size: 0.75rem;
        font-weight: 500;
    }
    
    .stepper-line {
        flex: 1;
        margin-left: 1rem;
        margin-right: 1rem;
        height: 2px;
    }

    /* Completed State */
    .stepper-completed .stepper-icon {
        background-color: var(--primary-600);
        border-color: var(--primary-600);
        color: white;
    }
    .stepper-completed .stepper-label {
        color: var(--primary-600);
    }
    .stepper-completed + .stepper-line {
        background-color: var(--primary-600);
    }

    /* Current State */
    .stepper-current .stepper-icon {
        background-color: rgb(255 255 255);
        border-color: var(--primary-600);
        color: var(--primary-600);
        box-shadow: 0 0 0 4px color-mix(in srgb, var(--primary-500) 20%, transparent);
    }
    .stepper-current .stepper-label {
        color: var(--primary-600);
        font-weight: 700;
    }
    .stepper-current + .stepper-line {
        background-color: rgb(228 228 231);
    }

    /* Upcoming State */
    .stepper-upcoming .stepper-icon {
        background-color: rgb(255 255 255);
        border-color: rgb(212 212 216);
        color: rgb(161 161 170);
    }
    .stepper-upcoming .stepper-label {
        color: rgb(113 113 122);
    }
    .stepper-upcoming + .stepper-line {
        background-color: rgb(228 228 231);
    }

    /* Dark mode adjustments */
    .dark .stepper-current .stepper-icon {
        background-color: rgb(24 24 27);
    }
    .dark .stepper-upcoming .stepper-icon {
        background-color: rgb(24 24 27);
        border-color: rgb(82 82 91);
    }
    .dark .stepper-current + .stepper-line,
    .dark .stepper-upcoming + .stepper-line {
        background-color: rgb(63 63 70);
    }
</style>

<div class="stepper-container">
    <ol class="stepper-list">
        @foreach($steps as $key => $label)
            @php
                $index = array_search($key, $stepKeys);
                $isCompleted = $index < $currentIndex;
                $isCurrent = $index === $currentIndex;
                $isLast = $index === count($steps) - 1;
                
                if ($isCompleted) {
                    $stateClass = 'stepper-completed';
                } elseif ($isCurrent) {
                    $stateClass = 'stepper-current';
                } else {
                    $stateClass = 'stepper-upcoming';
                }
            @endphp
            
            <li class="stepper-item">
                <div class="stepper-icon-wrapper {{ $stateClass }}">
                    <span class="stepper-icon" style="--primary-600: rgba(var(--primary-600), 1); --primary-500: rgba(var(--primary-500), 1);">
                        @if($isCompleted)
                            <svg style="width: 1rem; height: 1rem;" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 16 12">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 5.917 5.724 10.5 15 1.5"/>
                            </svg>
                        @else
                            <span>{{ $index + 1 }}</span>
                        @endif
                    </span>
                    <span class="stepper-label" style="--primary-600: rgba(var(--primary-600), 1);">
                        {{ $label }}
                    </span>
                </div>
                @if(!$isLast)
                    <div class="stepper-line" style="--primary-600: rgba(var(--primary-600), 1);"></div>
                @endif
            </li>
        @endforeach
    </ol>
</div>
