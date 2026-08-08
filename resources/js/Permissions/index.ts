import { usePage } from '@inertiajs/vue3';
import type { App, DirectiveBinding } from 'vue';
import Permissions from './Permissions';

export type PermissionType = {
    ability?: string;
    model?: string | null;
    id?: number | string | null;
};

type BindingValue = string | number | PermissionType | null;

// the arg is only split when the value doesn't already supply a model
const parseArg = (arg: string, model?: string | null): PermissionType => (model ? { ability: arg, model: model, id: null } : (Permissions.parsePermission(arg) as PermissionType));

const parseFromBinding = (binding: { arg?: string; value?: BindingValue }): PermissionType | null => {
    const value = binding.value ?? null;
    const arg = binding.arg ?? null;

    // v-permission="'ability-model'" or v-permission="{ ability, model, id }"
    if (arg === null) {
        return Permissions.parsePermission(value as PermissionType | string | null);
    }

    // v-permission:ability="{ model, id }" or v-permission:ability-model="{ id }"
    if (value !== null && typeof value === 'object') {
        return { ...parseArg(arg, value.model), ...value };
    }

    // v-permission:ability-model="id"
    return { ...parseArg(arg), id: value };
};

export default {
    install(app: App) {
        app.directive('permission', {
            beforeMount: async function (el: HTMLElement, binding: DirectiveBinding) {
                const permission = parseFromBinding(binding);

                if (permission === null) {
                    return;
                }

                el.setAttribute('v-cloak', '');

                if (await Permissions.check(permission)) {
                    el.removeAttribute('v-cloak');
                } else {
                    el.parentElement?.removeChild(el);
                }
            },
        });
        app.directive<HTMLElement, string>('role', {
            async beforeMount(el: HTMLElement, binding: DirectiveBinding) {
                const role = binding.arg ?? binding.value;

                if (role === null) {
                    return;
                }

                el.setAttribute('v-cloak', '');

                if (!usePage().props.auth.roles.includes(role)) {
                    el.removeAttribute('v-cloak');
                } else {
                    el.parentElement?.removeChild(el);
                }
            },
        });
    },
};
