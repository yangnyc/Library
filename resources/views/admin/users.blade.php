@extends('admin.layout')
@section('admin-title', 'Users')

@section('admin')
    <p class="text-body-secondary small">Editors manage the catalog and imports. Administrators can also manage languages and roles, and must use two-factor authentication. Staff cannot see or set other people’s passwords.</p>

    <form method="get" class="d-inline-flex flex-wrap align-items-center gap-2" role="search">
        <label class="form-label mb-0" for="q">Search email</label>
        <input class="form-control w-auto" id="q" name="q" type="search" value="{{ request('q') }}" dir="ltr">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Two-factor</th><th scope="col">Role</th></tr></thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td><bdi>{{ $user->name }}</bdi></td>
                        <td><bdi dir="ltr">{{ $user->email }}</bdi></td>
                        <td>{{ $user->hasTwoFactor() ? 'on' : 'off' }}</td>
                        <td>
                            <form method="post" action="{{ route('admin.users.update', $user->id) }}" class="d-inline-flex flex-wrap align-items-center gap-2">
                                @csrf @method('PUT')
                                <label for="role-{{ $user->id }}" class="visually-hidden">Role of {{ $user->email }}</label>
                                <select class="form-select w-auto" id="role-{{ $user->id }}" name="role">
                                    @foreach (\App\Models\User::ROLES as $role)
                                        <option value="{{ $role }}" @selected($user->role === $role)>{{ $role }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    {{ $users->links() }}
@endsection
