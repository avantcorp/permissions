import { useHttp, usePage } from '@inertiajs/vue3';
import { MutexRealm } from 'async-named-mutex';
import type { PermissionType } from './index';
import { check } from '@/routes/permissions';

const mutexRealm = new MutexRealm<string>();
type PermissionResults = { [key: string]: boolean };
const store: PermissionResults = {};
const pending: PermissionType[] = [];

const fetchActiveMutex = mutexRealm.createMutex('fetchActive');

const key = (permission: PermissionType): string => [permission.ability, permission.model, permission.id].filter((v) => v).join('-');

type NamedPermissions = { [key: string]: PermissionType };
const runFetch = async (permission: PermissionType) => {
    try {
        await fetchActiveMutex.acquire();
    } catch {
        await sleep(100);

        return runFetch(permission);
    }

    if (pending.find((p) => p === permission) === undefined) {
        fetchActiveMutex.release();

        return;
    }

    const toProcess: PermissionType[] = [];

    while (pending.length > 0) {
        toProcess.push(pending.shift() as PermissionType);
    }

    const permissionsToSend = toProcess.reduce(
        (result: NamedPermissions, permission: PermissionType) => ({
            ...result,
            [key(permission)]: permission,
        }),
        {},
    );

    await useHttp<NamedPermissions, PermissionResults>(permissionsToSend).post(check.url(), {
        onSuccess: (response: PermissionResults) => {
            Object.entries(response).forEach(([key, value]) => {
                store[key] = value;
            });

            fetchActiveMutex.release();
        },
    });
};

const sleep = async (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

const process = async (permissionKey: string, permission: PermissionType) => {
    if (!permission.id && (!permission.model || !(permission.model.startsWith('\\') || permission.model.endsWith('Policy')))) {
        if (store[permissionKey] !== undefined) {
            return;
        }

        const page = usePage();
        const partialAbility = permission.ability + (permission.model ? permission.model.substring(0, permission.model.indexOf('Policy') > 0 ? permission.model.indexOf('Policy') : permission.model.length) : '');

        if (!page.props.auth.permissions.includes(partialAbility) && !page.props.auth.roles.includes('superuser')) {
            store[permissionKey] = false;

            return;
        }
    }

    pending.push(permission);

    await sleep(100);
    await runFetch(permission);
};

export default {
    parsePermission(permission: PermissionType | string | null | undefined): PermissionType | null {
        if (permission === null || typeof permission === 'undefined') {
            return null;
        }

        if (typeof permission === 'string') {
            return { ability: permission, model: null, id: null };
        }

        return permission;
    },
    async check(permission: PermissionType | string | null | undefined) {
        const parsedPermission = this.parsePermission(permission);

        if (parsedPermission === null) {
            return true;
        }

        const permissionKey = key(parsedPermission);

        const mutex = mutexRealm.createMutex(permissionKey);
        await mutex.acquire();
        await process(permissionKey, parsedPermission);
        mutex.release();

        return store[permissionKey] ?? false;
    },
};
