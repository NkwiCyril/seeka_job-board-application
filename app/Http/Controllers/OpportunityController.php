<?php

namespace App\Http\Controllers;

use App\Mail\NewOpportunityMail;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class OpportunityController extends Controller
{
    /**
     * Display all published opportunities
     */
    public function index(): View
    {
        $opportunities = Opportunity::all();
        $published_opportunities = $opportunities->whereNotNull('published_at');

        return view('opportunities.publish', [
            'published_opportunities' => $published_opportunities,
        ]);
    }

    /**
     * Show a specific opportunity
     */
    public function show(int $id): View
    {
        $opportunity = Opportunity::findOrFail($id);
        $published_at = $opportunity->published_at_diff;

        return view('opportunities.show', [
            'opportunity' => $opportunity,
            'published_at' => $published_at,
        ]);
    }

    /**
     * Show the form for creating a new opportunity
     */
    public function create(): View
    {
        return view('opportunities.create');
    }

    /**
     * Store the newly created opportunity in the database
     *
     * @param  object  $request
     */
    public function store(Request $request): Redirector|RedirectResponse
    {
        // validate
        $validator = Validator::make($request->all(), [
            'title' => 'required|string',
            'image_url' => 'required|image',
            'description' => 'required|string',
            'category' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return redirect()->route('home.index')
                ->withErrors($validator)
                ->withInput();
        }

        $opportunity = $validator->validated();

        try {
            $file = $request->file('image_url');

            $fileName = $file->getClientOriginalName();
            $destinationPath = public_path('storage/images');
            $file->move($destinationPath, $fileName);
            $opportunity['image_url'] = '/storage/images/'.$fileName;

            $new = Opportunity::create([
                'title' => $opportunity['title'],
                'description' => $opportunity['description'],
                'image_url' => $opportunity['image_url'],
                'user_id' => auth()->user()->id,
                'category_id' => $opportunity['category'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return redirect()->route('home.index')->with('success', 'Opportunity has been created successfully!');

        } catch (\Exception $e) {
            logger()->error('Error encountered in creating opportunity: '.$e->getMessage());

            return redirect()->route('home.index')->with('error', 'Problem encountered! Try creating again.');
        }
    }

    /**
     * Show the form to edit a created opportunity
     */
    public function edit(int $id): View
    {
        $opportunity = Opportunity::findOrFail($id);

        return view('opportunities.edit', [
            'opportunity' => $opportunity,
        ]);
    }

    /**
     * Update a created opportunity in the database
     *
     * @param  object  $request
     */
    public function update(Request $request, int $id): Redirector|RedirectResponse
    {
        $validatedData = $request->validate([
            'title' => 'required',
            'image_url' => 'required',
            'description' => 'required',
            'category_id' => ['required' => 'max:1'],
        ]);

        $fileName = $request->file('image_url')->getClientOriginalName();
        $destinationPath = public_path('storage/images');
        $request->file('image_url')->move($destinationPath, $fileName);
        $validatedData['image_url'] = '/storage/images/'.$fileName;

        $opportunity = Opportunity::find($id);

        $opportunity->update($validatedData);

        return redirect()->route('home.index')->with('success', 'Opportunity has been updated successfully!');
    }

    /**
     * Remove an existing opportunity from the database
     */
    public function destroy(int $id): Redirector|RedirectResponse
    {
        Opportunity::where('id', $id)->delete();

        return redirect()->route('home.index')->with('success', 'Opportunity deleted successfully!');
    }

    /**
     * Publish an opportunity
     */
    public function publish(int $id): Redirector|RedirectResponse
    {
        $opportunity = Opportunity::findOrFail($id);
        $opportunity->published_at = now();
        $opportunity->save();

        $seekers = User::all();

        // call the created Artisan command to delete the opportunity after specified time

        Artisan::call('app:delete-old-opportunities');

        // notify all users of that category via email notification
        foreach ($seekers as $seeker) {
            if ($seeker->category === $opportunity->category->category_name) {

                $mailData = [
                    'opportunity' => $opportunity,
                    'seeker' => $seeker,
                ];

                try {
                    Mail::to($seeker->email)->queue(new NewOpportunityMail($mailData));
                } catch (\Exception $e) {
                    logger()->error('Error sending email to '.$seeker->email.': '.$e->getMessage());

                    return redirect()->route('opportunities.index')->with('success', 'Error encountered while sending email! Please try again');
                }

            }
        }

        // change message
        return redirect()->route('opportunities.index')->with('success', 'Opportunity published successfully!');

    }

    /**
     * Unpublish an opportunity
     */
    public function unpublish(int $id): Redirector|RedirectResponse
    {
        $opportunity = Opportunity::findOrFail($id);
        $opportunity->published_at = null;
        $opportunity->save();

        return redirect()->route('opportunities.index')->with('success', 'Opportunity unpublished successfully!');
    }
}
