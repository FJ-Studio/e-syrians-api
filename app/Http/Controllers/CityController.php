<?php
namespace App\Http\Controllers;
use App\Models\City;
use App\Helper\Helper;
use Illuminate\Http\Request;
use App\Http\Requests\CityRequest;
use App\Http\Resources\CityResource;
use App\Http\Resources\paginateResource;
class CityController extends Controller
{
    public function index(Request $request)
    {
        $items = City::query()->with('children');
        if ($request->parent_id) {
            $items->where('parent_id' , $request->parent_id);
        }
        else {
            $items->whereNull('parent_id');
        }
        $items = $items->orderBy('id' , $request->sort ? $request->sort : 'desc');
        $items = $items->paginate($request->perPage ? $request->perPage : 20);
        $date['data'] = CityResource::collection($items);
        if ($items instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $date['paginate'] = new paginateResource($items);
        }
        return Helper::apiResponse($date);
    }
    public function store(CityRequest $request)
    {
        return $this->update($request);
        //        $item = new City();
        //        if ($request->filled('parent')) {
        //            $parent = City::query()->where('uuid' , $request->parent)->first();
        //            if ($parent) {
        //                $item->parent_id = $parent->id;
        //            }
        //        }
        //        $item->setTranslation('name' , 'ar' , $request->name_ar)->save();
        //        $item->setTranslation('name' , 'en' , $request->name_en)->save();
        //        $item->save();
        //        $date['data'] = CityResource::make($item);
        //        return Helper::apiResponse($date);
    }
    public function show($uuid)
    {
        $city = City::where('uuid' , $uuid)->with('children')->first();
        if (!$city) {
            return Helper::apiResponse('not found' , 404);
        }
        $date['data'] = CityResource::make($city);
        return Helper::apiResponse($date);
    }
    public function update(CityRequest $request , $uuid = null)
    {
        if (!is_null($uuid)) {
            $item = City::where('uuid' , $uuid)->first();
            if (!$item) {
                return Helper::apiResponse('not_found' , 404);
            }
        }
        else {
            $item = new City();
        }
        if ($request->filled('parent')) {
            $parent = City::query()->where('uuid' , $request->parent)->first();
            if ($parent) {
                $item->parent_id = $parent->id;
            }
        }
        $item->setTranslation('name' , 'ar' , $request->name_ar)->save();
        $item->setTranslation('name' , 'en' , $request->name_en)->save();
        $item->save();
        $date['data'] = CityResource::make($item);
        return Helper::apiResponse($date);
    }


    public function destroy($uuid)
    {
        $item = City::where('uuid' , $uuid)->first();
        if (!$item) {
            return Helper::apiResponse('not_found' , 404);
        }
        $item->delete();
        return Helper::apiResponse('deleted');
    }
}
