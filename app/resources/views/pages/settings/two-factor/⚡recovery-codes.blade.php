<?php

use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->loadRecoveryCodes();
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        $generateNewRecoveryCodes(auth()->user());

        $this->loadRecoveryCodes();
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $this->recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            } catch (Exception) {
                $this->addError('recoveryCodes', 'Failed to load recovery codes');

                $this->recoveryCodes = [];
            }
        }
    }
}; ?>

<div
    class="py-4 sm:py-6 space-y-6 border shadow-sm rounded-xl border-zinc-200 dark:border-white/10"
    wire:cloak
    x-data="{ showRecoveryCodes: false }"
>
    {{-- Relleno lateral más ajustado en móvil para no comerse el ancho útil. --}}
    <div class="px-4 sm:px-6 space-y-2">
        <div class="flex items-center gap-2">
            <flux:icon.lock-closed variant="outline" class="size-4"/>
            <flux:heading size="lg" level="3">{{ __('2FA recovery codes') }}</flux:heading>
        </div>
        {{-- Esta advertencia iba en la variante "subtle" de Flux, que sobre el fondo
             oscuro se queda en blanco al 50 %. Es la instrucción de la que depende no
             perder el acceso a la cuenta: no puede ser el texto más apagado del cuadro. --}}
        <flux:text class="text-zinc-600 dark:text-zinc-300">
            {{ __('Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.') }}
        </flux:text>
    </div>

    <div class="px-4 sm:px-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <flux:button
                x-show="!showRecoveryCodes"
                icon="eye"
                icon:variant="outline"
                variant="primary"
                class="min-h-11"
                @click="showRecoveryCodes = true;"
                aria-expanded="false"
                aria-controls="recovery-codes-section"
            >
                {{ __('View recovery codes') }}
            </flux:button>

            <flux:button
                x-show="showRecoveryCodes"
                icon="eye-slash"
                icon:variant="outline"
                variant="primary"
                class="min-h-11"
                @click="showRecoveryCodes = false"
                aria-expanded="true"
                aria-controls="recovery-codes-section"
            >
                {{ __('Hide recovery codes') }}
            </flux:button>

            @if (filled($recoveryCodes))
                <flux:button
                    x-show="showRecoveryCodes"
                    icon="arrow-path"
                    variant="filled"
                    class="min-h-11"
                    wire:click="regenerateRecoveryCodes"
                >
                    {{ __('Regenerate codes') }}
                </flux:button>
            @endif
        </div>

        <div
            x-show="showRecoveryCodes"
            x-transition
            id="recovery-codes-section"
            class="relative overflow-hidden"
            x-bind:aria-hidden="!showRecoveryCodes"
        >
            <div class="mt-3 space-y-3">
                @error('recoveryCodes')
                    <flux:callout variant="danger" icon="x-circle" heading="{{$message}}"/>
                @enderror

                @if (filled($recoveryCodes))
                    <div
                        {{-- Una sola columna y corte de palabra: los códigos no pueden desbordar a lo ancho.

                             El color del texto es explícito y al máximo de contraste: quien
                             apunte mal un código pierde el acceso a su cuenta, así que estos
                             caracteres son los que mejor se tienen que leer de la pantalla.
                             Sin declararlo heredaban el color del cuerpo. --}}
                        class="grid grid-cols-1 gap-1 p-3 sm:p-4 font-mono text-xs sm:text-sm tracking-wider break-all rounded-lg bg-zinc-100 text-zinc-900 dark:bg-white/5 dark:text-white"
                        role="list"
                        aria-label="{{ __('Recovery codes') }}"
                    >
                        @foreach($recoveryCodes as $code)
                            <div
                                role="listitem"
                                class="select-text"
                                wire:loading.class="opacity-50 animate-pulse"
                            >
                                {{ $code }}
                            </div>
                        @endforeach
                    </div>
                    <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">
                        {{ __('Each recovery code can be used once to access your account and will be removed after use. If you need more, click Regenerate codes above.') }}
                    </flux:text>
                @endif
            </div>
        </div>
    </div>
</div>
