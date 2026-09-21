<div class="w-full max-w-3xl space-y-6">
    <div>
        <flux:heading size="xl">Sesiones activas</flux:heading>
        <flux:subheading>
            Cada dispositivo donde inició sesión aparece abajo. Si reconoce alguno que no es suyo,
            ciérrelo y cambie su contraseña de inmediato.
        </flux:subheading>
    </div>

    @if (! $this->disponible)
        <flux:callout variant="warning" icon="exclamation-triangle" heading="No se pueden enumerar las sesiones">
            <flux:callout.text>
                El controlador de sesión configurado no es <span class="font-mono">database</span>, así que la
                aplicación no tiene forma de saber qué sesiones existen. Se prefiere decirlo antes que mostrar
                una lista vacía, que se leería como &laquo;no hay sesiones abiertas&raquo;.
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            @forelse ($this->sesiones as $sesion)
                <div class="flex items-center justify-between gap-4 p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                    <div class="flex items-center gap-4">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                            <flux:icon.computer-desktop class="size-5 text-zinc-500 dark:text-zinc-400" />
                        </div>

                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <p class="font-medium tracking-tight">{{ $sesion['dispositivo'] }}</p>

                                @if ($sesion['es_actual'])
                                    <flux:badge size="sm" color="lime">Esta sesión</flux:badge>
                                @endif
                            </div>

                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                <span class="font-mono">{{ $sesion['ip'] }}</span>
                                <span class="mx-1 opacity-50">/</span>
                                Última actividad {{ $sesion['ultima_actividad']->diffForHumans() }}
                            </p>
                        </div>
                    </div>

                    @unless ($sesion['es_actual'])
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="x-mark"
                            icon:variant="outline"
                            wire:click="cerrarUna('{{ $sesion['id'] }}')"
                            wire:loading.attr="disabled"
                            class="text-red-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/50"
                        >
                            Cerrar
                        </flux:button>
                    @endunless
                </div>
            @empty
                <div class="p-8 text-center">
                    <p class="font-medium">No hay otras sesiones registradas</p>
                    <flux:text class="mt-1">Solo está abierta la sesión de este navegador.</flux:text>
                </div>
            @endforelse
        </div>

        <flux:separator />

        <form wire:submit="cerrarOtras" class="space-y-4">
            <div>
                <flux:heading>Cerrar todas las demás sesiones</flux:heading>
                <flux:subheading>
                    Expulsa cualquier otro dispositivo, incluidos los que marcaron &laquo;recordarme&raquo;.
                    Se pide la contraseña porque tener la cookie de esta sesión no prueba que usted sea
                    el dueño de la cuenta.
                </flux:subheading>
            </div>

            <flux:input
                wire:model="contrasena"
                label="Contraseña"
                type="password"
                autocomplete="current-password"
                viewable
                required
            />

            <flux:button variant="danger" type="submit" wire:loading.attr="disabled">
                Cerrar las demás sesiones
            </flux:button>
        </form>
    @endif
</div>
