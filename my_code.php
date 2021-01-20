<?php
   /*ExportController.php*/
class ExportController extends BaseController{
   /**
     * @Route("/export/day_info", name="export_info_by_day", methods={"GET"})
     * @param Request $request
     * @return Response
     */
    public function exportPeopleMoney(Request $request): Response
    {
        $week =["Воскресенье","Понедельник","Вторник","Среда","Четверг","Пятница","Суббота"];
        $db_date = $request->query->get('db_date') ?? date("Y-m-d", strtotime("-1 day"));

        $recordId = $request->query->get('record_id') ?? null;
        $driverId = $request->query->get('driver_id') ?? null;
        if(isset($driverId) && $driverId > 0){
            $driver = $this->getDoctrine()->getRepository( Driver::class)->find($driverId);
            $driverName = $driver->getDriverName();
        }
        $exportArr = array();
        if(isset($recordId)){
            $byDayInfo = $this->getDoctrine()->getRepository( GelTariffData::class)->find($recordId);
            $currCar = $byDayInfo->getCar();
            $currCarNumber = $currCar != null ? $currCar->getCarNumber() : null;

            $geliosData = $byDayInfo->getGeliosData();
            $allTrips = count($geliosData["direction_info"]);//всего рейсов было

            $tripInfo = $this->countTripPeople($byDayInfo->getPeopleList());//формирование нужного массива рейсов

            $route = $byDayInfo->getRoute();
            $routeName = $route->getRouteName();
            $routeTariff = $route->getTariffJson();

            $routeRegularPrice = $routeTariff["price_regular"];
            $routeStudentPrice = $routeTariff["price_student"];

            $exportArr[] = ['Рейс','Пассажиров','Обычных','Льготников','Учеников','Сумма','Начало рейса','Конец рейса'];
            foreach($tripInfo['trips'] as $num => $trip){
                $indexes = $geliosData['direction_info'][$num]["indexes"];//индексы старта и конца рейса
                $sumRegulars = $routeRegularPrice * $trip["regulars"];
                $sumStudents = $routeStudentPrice * $trip["students"];
                $exportArr[] = [($num+1),
                    ($trip['regulars']+$trip['students']+$trip['beneficiary']),
                    $trip['regulars'],
                    $trip['beneficiary'],
                    $trip['students'],
                    ($sumRegulars+$sumStudents),
                    substr($geliosData['zones_dict'][$indexes[0]]['date'],11),
                    substr($geliosData['zones_dict'][$indexes[1]]['date'],11),
                    ];
            }

            for($i=0;$i <2; $i++) $exportArr[] = [];
            $sumRegulars = $routeRegularPrice * $tripInfo["byDay"]["regulars"];
            $sumStudents = $routeStudentPrice * $tripInfo["byDay"]["students"];

            $finishTime = $geliosData['zones_dict'][
                $geliosData['direction_info'][array_key_last($geliosData['direction_info'])]['indexes'][1]
            ]['date'];
            $startTime = $geliosData['zones_dict'][
                $geliosData['direction_info'][0]['indexes'][0]
            ]['date'];
            $startTime = new \DateTime($startTime);//время начала рейса
            $finishTime = new \DateTime($finishTime);//время конца рейса

            $exportArr[] = ['Дата:',$db_date];
            $exportArr[] = ['День недели:', $week[date('w',strtotime($db_date))] ];
            $exportArr[] = ['№ маршрута:', $routeName];
            $exportArr[] = ['№ автобуса:', $currCarNumber];
            $exportArr[] = isset($driverName) ? ['Водитель:',$driverName] : ['Водитель:','-'];
            $exportArr[] = ['На маршруте:', $finishTime->diff($startTime)->format('%H:%I:%S')];
            $exportArr[] = ['Рейсов:', $allTrips];

            $exportArr[] = ['Пассажиров'];
            $exportArr[] = ['Всего:',($tripInfo["byDay"]["regulars"]+$tripInfo["byDay"]["beneficiary"]+$tripInfo["byDay"]["students"])];
            $exportArr[] = ['Обычных:',$tripInfo["byDay"]["regulars"]];
            $exportArr[] = ['Льготных:',$tripInfo["byDay"]["beneficiary"]];
            $exportArr[] = ['Ученических:',$tripInfo["byDay"]["students"]];
            $exportArr[] = ['Тариф:',$routeRegularPrice.'->'.$routeStudentPrice];
            $exportArr[] = ['Касса:',($sumRegulars + $sumStudents)];

            for($i=0;$i <2; $i++) $exportArr[] = [];
            $exportArr[] = ['0грн * '.$tripInfo["byDay"]["beneficiary"].' = 0грн'];
            $exportArr[] = [$routeRegularPrice.'грн * '.$tripInfo["byDay"]["regulars"].' = '.$sumRegulars.'грн'];
            $exportArr[] = [$routeStudentPrice.'грн * '.$tripInfo["byDay"]["students"].' = '.$sumStudents.'грн'];
            $exportArr[] = ['ИТОГО = '.($sumRegulars + $sumStudents).'грн'];

        }

        $filename = "info_by_".$db_date.".csv";
        $content = trim($this->array2csv($exportArr));
        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment;filename='.$filename);

        return $response;
    }
}
    /*TariffsController.php*/
class CabinetTariffController extends AdminBaseController{
     /** 
     * @Route("/cabinet/tariff/city/create", name="cabinet_tariff_create")
     * @param Request $request
     * @return Response
     */
    public function createCity(Request $request)
    {
        $form = $this->createForm(TariffType::class);
        $em = $this->getDoctrine()->getManager();

        if ($request->isMethod('POST')) {
            $tariffForm = $request->request->get('tariff');
            $putContent = new TariffZones();
            $existsVars = 0;
            if (isset($tariffForm["route_name"])) {
                $putContent->setRouteName($tariffForm["route_name"]);
                $existsVars++;
            }
            if (isset($tariffForm["tariff_regular"])) {
                $newJson = array(
                    "type" => 'city',
                    'price_regular' => $tariffForm["tariff_regular"],
                    'price_student' => $tariffForm["tariff_student"] ?? 0
                );
                $putContent->setTariffJson($newJson);
                $existsVars++;
            }
            if (isset($tariffForm["company"])) {
                if($this->isGranted('ROLE_ADMIN')){
                    $company = $this->getDoctrine()->getRepository(Companies::class)->find($tariffForm["company"]);
                }
                else if(!$this->isGranted('ROLE_ADMIN') && $this->isGranted('ROLE_EDITOR')){
                    $currCompany = $this->getUser()->getCompany();
                    if($currCompany != null){
                        $company = $currCompany;
                    }
                }
                $putContent->setCompany($company);
                $existsVars++;
            }
            if ($existsVars == 3) {
                $description = "городской маршрут " . $tariffForm["route_name"];
                $this->createHistoryRecord(15, $description, $this->getUser());// в историю "Добавление тарифа"

                $em->persist($putContent);
                $em->flush();
                $this->addFlash('success', 'Тариф добавлен');
                return $this->redirectToRoute('cabinet_tariff');
            } else {
                $this->addFlash('danger', 'Некоторые поля не указаны');
            }

        }
     }
        
     /**
     * @Route("/cabinet/tariff/city/update/{id}", name="cabinet_tariff_update")
     * @param int $id
     * @param Request $request
     * @return Response
     */
    public function updateCity(int $id, Request $request)
    {
        $tariff = $this->getDoctrine()->getRepository(TariffZones::class)
            ->find($id);
        $prices = $tariff->getTariffJson();

        $form = $this->createForm(TariffType::class);
        # <заполнение полей формы>
        if(isset($prices['price_regular']) && isset($prices['price_student'])){
            $form->get('tariff_regular')->setData($prices['price_regular']);
            $form->get('tariff_student')->setData($prices['price_student']);
        }
        $form->get('route_name')->setData($tariff->getRouteName());
        $form->get('company')->setData($tariff->getCompany());
        # </заполнение полей формы>
        $em = $this->getDoctrine()->getManager();

        $postInfo = $request->request->get('tariff');//по нажатию на submit. 'tariff' - группа переданых значений полей
        if($request->getMethod() == 'POST' && ( $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_EDITOR') )){
            if(isset($postInfo['save'])){#проверка на клик
                $newJson = array(
                    "type" => 'city',
                    'price_regular' => $postInfo["tariff_regular"],
                    'price_student' => $postInfo["tariff_student"] ?? $postInfo["tariff_regular"]
                );

                $tariff->setTariffJson($newJson);
                $tariff->setRouteName($postInfo["route_name"]);
                $tariff->setCompany($this->getDoctrine()->getRepository(Companies::class)
                    ->find($postInfo["company"]));
                $this->addFlash('success', 'Тариф маршрута '.$tariff->getRouteName().' обновлён');
            }
            $em->flush();
            return $this->redirectToRoute('cabinet_tariff');
        }

        $forRender = parent::renderDefault();
        $forRender['title'] = "Редактирование тарифа";
        $forRender['form'] = $form->createView();
        $forRender['formType'] = "edit";
        return $this->render("cabinet/tariff/form.html.twig",$forRender);
    }
   
    /**
     * @Route("/cabinet/tariff/delete/{id}", name="cabinet_tariff_delete")
     * @param int $id
     * @return Response
     */
    public function delete(int $id){
        $tariff = $this->getDoctrine()->getRepository(TariffZones::class)
            ->find($id);
        $cars = $tariff->getCars();
        $carsList = "";
        foreach ($cars as $car){
            $carsList .= $car->getCarNumber()."; ";
        }
        if($carsList != ""){
            $this->addFlash('danger', 'Перед удалением уберите тариф с этих машин: '. $carsList);
            return $this->redirectToRoute('cabinet_tariff');
        }
        $em = $this->getDoctrine()->getManager();
        $em->remove($tariff);
        $em->flush();
        $this->addFlash('danger', 'Тариф маршрута '.$tariff->getRouteName().' удален');
        return $this->redirectToRoute('cabinet_tariff');
    }
}
