<div class="timer">
    Tempo restante:
    <span wire:poll.1s="checkExpiration">{{ $formattedTimeLeft }}</span>
</div>
