<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Todo;

class TodoController extends Controller
{
    public function index()
    {
        $todos = Auth::user()->todos;
        return response()->json([
            'status' => 'success',
            'todos' => $todos,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:255',
        ]);

        $todo = Auth::user()->todos()->create([
            'title' => $request->title,
            'description' => $request->description,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Todo created successfully',
            'todo' => $todo,
        ]);
    }

    public function show($id)
    {
        $todo = Todo::find($id);

        if ($todo && $todo->user_id == Auth::id()) {
            return response()->json([
                'status' => 'success',
                'todo' => $todo,
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized',
        ], 403);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:255',
        ]);

        $todo = Todo::find($id);

        if ($todo && $todo->user_id == Auth::id()) {
            $todo->title = $request->title;
            $todo->description = $request->description;
            $todo->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Todo updated successfully',
                'todo' => $todo,
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized',
        ], 403);
    }

    public function destroy($id)
    {
        $todo = Todo::find($id);

        if ($todo && $todo->user_id == Auth::id()) {
            $todo->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Todo deleted successfully',
                'todo' => $todo,
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized',
        ], 403);
    }
}
